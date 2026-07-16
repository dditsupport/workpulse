using System;
using System.Collections.Generic;
using System.Drawing;
using System.Linq;
using System.Threading.Tasks;
using System.Windows.Forms;
using BiometricAttendance.App.Helpers;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Core.Models;
using BiometricAttendance.Core.Services;

namespace BiometricAttendance.App.Forms
{
    /// <summary>
    /// Enrollment form.
    ///
    /// Capture loop behaviour:
    ///   1. Capture finger → show image + NFIQ.
    ///   2. If NFIQ > 3 (Fair/Poor): show contextual instruction, automatically
    ///      restart capture so user can adjust finger without clicking again.
    ///   3. If NFIQ ≤ 3 (Excellent/Very Good/Good): count as a valid capture.
    ///   4. After 3 valid captures: consistency check → duplicate check
    ///      (with live "X / N fingers" progress bar) → save.
    ///
    /// FM220 preview: SDK paints directly into picPreview via its hwnd.
    /// MFS500 preview: bitmap callbacks fired via OnPreview event.
    /// </summary>
    public partial class EnrollmentForm : Form
    {
        private readonly DeviceType         _deviceType;
        private readonly ApiService         _apiService;
        private readonly AttendanceService  _attendanceService;
        private readonly IFingerprintDevice _device;

        private const int RequiredCaptures = 3;
        private readonly List<CaptureResult> _captures = new List<CaptureResult>();

        private bool   _capturing    = false;
        private bool   _looping      = false;   // true while auto-retry loop is running
        private string _selectedCode = "";
        private string _selectedName = "";
        private bool   _isOverwrite  = false;

        // Tracks an in-flight CaptureTemplate Task so OnFormClosing can wait
        // for it before the form is disposed — otherwise a late SDK callback
        // fires onto a destroyed picPreview handle.
        private Task   _activeCaptureTask;
        private int    _previewBusy;       // 0 = idle, 1 = BeginInvoke pending
        private readonly System.Threading.CancellationTokenSource _formCts =
            new System.Threading.CancellationTokenSource();

        public EnrollmentForm(
            DeviceType         deviceType,
            ApiService         apiService,
            AttendanceService  attendanceService,
            IFingerprintDevice device)
        {
            _deviceType        = deviceType;
            _apiService        = apiService;
            _attendanceService = attendanceService;
            _device            = device;

            InitializeComponent();
            ApplyTheme();

            // MFS500 bitmap preview
            _device.OnPreview         += OnDevicePreview;
            // SDK status strings (FM220 mainly)
            _device.OnProgressMessage += OnDeviceProgress;

            btnLookup.Click  += async (s, e) => await LookupEmployeeAsync();
            btnCapture.Click += async (s, e) => await StartCaptureLoopAsync();
            btnReset.Click   += (s, e) => ResetForm();

            txtEmployeeCode.KeyDown += (s, e) =>
            {
                if (e.KeyCode == Keys.Enter)
                { e.SuppressKeyPress = true; _ = LookupEmployeeAsync(); }
            };
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            _looping = false;
            _formCts.Cancel();

            // Wait briefly for any in-flight CaptureTemplate so the SDK's
            // ScanComplete callback returns before we tear down picPreview.
            // Capped at the device's own timeout (12s for FM220) plus a
            // small grace; the user's Cancel/X click already happened.
            var pending = _activeCaptureTask;
            if (pending != null && !pending.IsCompleted)
            {
                try { pending.Wait(TimeSpan.FromSeconds(13)); }
                catch { /* swallow — device errors are logged by the device */ }
            }

            _device.OnPreview         -= OnDevicePreview;
            _device.OnProgressMessage -= OnDeviceProgress;
            ClearPreview();
            base.OnFormClosing(e);
        }

        // ── Theme ─────────────────────────────────────────────────────────────
        private void ApplyTheme()
        {
            this.Text = $"Enrollment  ·  {UiTheme.DeviceLabel(_deviceType)}";
            this.BackColor = UiTheme.Background;
            this.ForeColor = UiTheme.TextPrimary;

            lblTitle.ForeColor        = UiTheme.TextPrimary;
            lblDeviceBadge.Text       = UiTheme.DeviceLabel(_deviceType);
            lblDeviceBadge.BackColor  = UiTheme.Accent(_deviceType);
            lblDeviceBadge.ForeColor  = Color.White;
            lblCodePrompt.ForeColor   = UiTheme.TextSecondary;
            txtEmployeeCode.BackColor = UiTheme.Surface;
            txtEmployeeCode.ForeColor = UiTheme.TextPrimary;
            panelEmp.BackColor        = UiTheme.Surface;
            picPreview.BackColor      = UiTheme.Surface;

            UiTheme.ApplyAccent(btnLookup,  _deviceType);
            UiTheme.ApplyAccent(btnCapture, _deviceType);

            btnReset.BackColor = UiTheme.Surface;
            btnReset.ForeColor = UiTheme.TextSecondary;
            btnReset.FlatAppearance.BorderColor = UiTheme.SurfaceLight;

            dot1.BackColor = UiTheme.SurfaceLight;
            dot2.BackColor = UiTheme.SurfaceLight;
            dot3.BackColor = UiTheme.SurfaceLight;

            lblProgress.ForeColor    = UiTheme.TextSecondary;
            lblInstruction.ForeColor = UiTheme.Info;
            // Seed the always-visible placeholder so the user sees the
            // image-quality readout exists before the first scan; the
            // SetNfiqBadge() helper overwrites it after each capture.
            lblNfiqBadge.ForeColor   = UiTheme.TextSecondary;
            lblNfiqBadge.Text        = "Image quality: —";
            lblDupProgress.ForeColor = UiTheme.TextSecondary;
            lblDupProgress.Visible   = false;
            progressDup.Visible      = false;

            btnCapture.Enabled = false;
            btnReset.Enabled   = false;

            SetStatus("Enter employee code and click Lookup to begin.", UiTheme.Info);
            SetInstruction("");
        }

        // =====================================================================
        // LOOKUP
        // =====================================================================
        private async Task LookupEmployeeAsync()
        {
            string code = txtEmployeeCode.Text.Trim().ToUpper();
            if (string.IsNullOrEmpty(code))
            { SetStatus("Please enter an employee code.", UiTheme.Warning); return; }

            ResetCaptureState();
            SetStatus("Looking up employee…", UiTheme.Info);
            btnLookup.Enabled = false;

            try
            {
                var emp = await _apiService.GetEmployeeByCodeAsync(code);
                if (emp == null)
                { SetStatus("Employee code not found.", UiTheme.Error); ClearEmployeePanel(); return; }

                _selectedCode = emp.EmployeeCode;
                _selectedName = emp.FullName;
                ShowEmployeePanel(emp);

                _isOverwrite = emp.HasTemplate(_deviceType);
                if (_isOverwrite)
                {
                    var confirm = MessageBox.Show(
                        $"{emp.FullName} ({emp.EmployeeCode}) already has a " +
                        $"{UiTheme.DeviceLabel(_deviceType)} template enrolled.\n\nOverwrite it?",
                        "Overwrite Existing Template", MessageBoxButtons.YesNo, MessageBoxIcon.Question);
                    if (confirm != DialogResult.Yes)
                    { ResetCaptureState(); SetStatus("Overwrite cancelled.", UiTheme.Info); return; }
                }

                btnCapture.Enabled = true;
                btnReset.Enabled   = true;
                SetStatus($"Ready — click Capture and place finger ({RequiredCaptures} samples needed)", UiTheme.Accent(_deviceType));
                SetInstruction("Place your finger firmly on the sensor.");
            }
            catch (Exception ex) { SetStatus("Lookup failed: " + ex.Message, UiTheme.Error); }
            finally { btnLookup.Enabled = true; }
        }

        // =====================================================================
        // CAPTURE LOOP
        // Keeps capturing until a valid quality finger is obtained for each slot.
        // Poor quality → instruction shown → automatic retry (no button click needed).
        // =====================================================================
        private async Task StartCaptureLoopAsync()
        {
            if (_capturing) return;
            _capturing = true;
            _looping   = true;
            btnCapture.Enabled = false;

            // For FM220: give SDK the PictureBox handle so it can paint preview
            if (_deviceType == DeviceType.FM220)
                _device.SetPreviewHandle(picPreview.Handle);

            HideDupProgress();

            try
            {
                while (_looping && _captures.Count < RequiredCaptures)
                {
                    int slot = _captures.Count + 1;
                    SetStatus($"Capture {slot}/{RequiredCaptures} — place finger on sensor…",
                        UiTheme.Accent(_deviceType));
                    SetInstruction("Place your finger firmly and flat on the sensor.");
                    SetNfiqBadge(0);

                    var captureTask = Task.Run(() => _device.CaptureTemplate());
                    _activeCaptureTask = captureTask;
                    CaptureResult? result;
                    try { result = await captureTask; }
                    finally { if (_activeCaptureTask == captureTask) _activeCaptureTask = null; }

                    if (!_looping) break;

                    if (result == null)
                    {
                        // Timeout — stop loop, let user restart
                        SetStatus("Timeout — click Capture to try again.", UiTheme.Warning);
                        SetInstruction("Place finger on sensor when ready.");
                        break;
                    }

                    var capture = result.Value;
                    SetNfiqBadge(capture.Nfiq);

                    // For MFS500: preview already shown via OnPreview event.
                    // For FM220: SDK has already painted into picPreview.

                    if (capture.Nfiq > 3)
                    {
                        // Poor quality — show instruction, auto-retry
                        string instruction = GetQualityInstruction(capture.Nfiq, capture.Quality);
                        SetStatus($"Quality too low (NFIQ: {capture.Nfiq} — {NfiqLabel(capture.Nfiq)}) — adjusting…",
                            UiTheme.Warning);
                        SetInstruction(instruction);

                        // Brief pause so user can read the message, then auto-retry
                        await Task.Delay(1800);

                        if (!_looping) break;
                        // Loop continues → captures again automatically
                        continue;
                    }

                    // ── Good quality capture ──────────────────────────────────
                    _captures.Add(capture);
                    UpdateCaptureProgress();

                    if (_captures.Count < RequiredCaptures)
                    {
                        SetStatus(
                            $"✓ Good! ({NfiqLabel(capture.Nfiq)}) — " +
                            $"place the SAME finger again ({_captures.Count + 1}/{RequiredCaptures})",
                            UiTheme.Success);
                        SetInstruction("Lift and re-place the same finger.");
                        await Task.Delay(1200);   // brief pause before next capture
                    }
                }

                if (!_looping) return;

                if (_captures.Count < RequiredCaptures)
                {
                    // Loop ended early (timeout or cancel)
                    btnCapture.Enabled = true;
                    return;
                }

                // ── All 3 captures done ───────────────────────────────────────
                await ProcessCapturesAsync();
            }
            catch (Exception ex)
            {
                SetStatus("Error: " + ex.Message, UiTheme.Error);
                SetInstruction("");
                btnCapture.Enabled = !string.IsNullOrEmpty(_selectedCode);
                btnReset.Enabled   = true;
            }
            finally
            {
                _capturing = false;
                _looping   = false;
            }
        }

        // =====================================================================
        // PROCESS AFTER 3 GOOD CAPTURES
        // =====================================================================
        // Enrolment-only matcher. A FM220 matcher error (FingerprintMatchException
        // from Fm220Device.Match) is treated here as a non-match (score 0) — the same
        // way the wrapper behaved before the throw was added — so a low-minutiae
        // capture shows the normal "finger mismatch — retry" path instead of a raw SDK
        // error during enrolment. Attendance still calls _device.Match directly, so
        // genuine matcher errors there are logged distinctly (match_sdk_error), not
        // masked.
        private int MatchForEnroll(byte[] a, byte[] b)
        {
            try { return _device.Match(a, b); }
            catch (FingerprintMatchException) { return 0; }
        }

        private async Task ProcessCapturesAsync()
        {
            // ── Consistency check ─────────────────────────────────────────────
            SetStatus("Checking finger consistency…", UiTheme.Info);
            SetInstruction("Please wait…");

            int s01    = MatchForEnroll(_captures[0].Template, _captures[1].Template);
            int s02    = MatchForEnroll(_captures[0].Template, _captures[2].Template);
            int norm01 = ScoreHelper.Normalize(s01, _deviceType);
            int norm02 = ScoreHelper.Normalize(s02, _deviceType);

            if (norm01 < 50 || norm02 < 50)
            {
                SetStatus(
                    $"Finger mismatch detected (scores: {norm01}, {norm02}) — " +
                    $"all 3 samples must be from the same finger.",
                    UiTheme.Error);
                SetInstruction("Use the same finger for all captures. Click Capture to retry.");
                _captures.Clear();
                UpdateCaptureProgress();
                btnCapture.Enabled = true;
                return;
            }

            var best = _captures.OrderByDescending(c => c.Quality).First();

            // ── Duplicate check with live progress bar ────────────────────────
            SetStatus("Checking for duplicate fingerprints in database…", UiTheme.Info);
            SetInstruction("Please wait…");
            ShowDupProgress(0, 1);

            Exception dupException = null;

            await Task.Run(() =>
            {
                try
                {
                    _attendanceService.CheckDuplicate(
                        _selectedCode, best.Template, _deviceType, MatchForEnroll,
                        (current, total) =>
                        {
                            Action update = () =>
                            {
                                if (total > 0)
                                {
                                    progressDup.Maximum = total;
                                    progressDup.Value   = Math.Min(current, total);
                                    lblDupProgress.Text =
                                        $"Checking: {current} / {total} fingerprints…";
                                }
                            };
                            if (progressDup.InvokeRequired)
                                progressDup.BeginInvoke(update);
                            else
                                update();
                        });
                }
                catch (DuplicateFingerprintException ex) { dupException = ex; }
                catch (Exception ex)                     { dupException = ex; }
            });

            HideDupProgress();

            if (dupException is DuplicateFingerprintException dup)
            {
                SetStatus(dup.Message, UiTheme.Error);
                SetInstruction("This finger is already registered to another employee.");
                _captures.Clear();
                UpdateCaptureProgress();
                btnCapture.Enabled = true;
                return;
            }
            if (dupException != null)
            { SetStatus("Error: " + dupException.Message, UiTheme.Error); btnCapture.Enabled = true; return; }

            // ── Save to server ────────────────────────────────────────────────
            SetStatus("Saving template to server…", UiTheme.Info);
            SetInstruction("Please wait…");
            btnCapture.Enabled = false;
            btnReset.Enabled   = false;

            bool saved = await _apiService.EnrollFingerAsync(_selectedCode, best.Template);
            if (!saved)
            {
                SetStatus("Save failed — check connection and try again.", UiTheme.Error);
                SetInstruction("Check your internet connection.");
                _captures.Clear();
                UpdateCaptureProgress();
                btnCapture.Enabled = true;
                btnReset.Enabled   = true;
                return;
            }

            // ── Reload ────────────────────────────────────────────────────────
            var employees = await _apiService.GetEmployeesAsync();
            _attendanceService.LoadEmployees(employees);

            string action = _isOverwrite ? "updated" : "enrolled";
            SetStatus($"✔  {_selectedName} {action} successfully!", UiTheme.Success);
            SetInstruction("");

            MessageBox.Show(
                $"Enrollment complete!\n\n" +
                $"Employee : {_selectedName} ({_selectedCode})\n" +
                $"Device   : {UiTheme.DeviceLabel(_deviceType)}\n" +
                $"Status   : {action.ToUpper()}\n" +
                $"Quality  : {NfiqLabel(best.Nfiq)}  (NFIQ {best.Nfiq})",
                "Enrollment Successful", MessageBoxButtons.OK, MessageBoxIcon.Information);

            ResetForm();
        }

        // ── Reset ─────────────────────────────────────────────────────────────
        private void ResetForm()
        {
            _looping = false;
            txtEmployeeCode.Clear();
            _selectedCode = ""; _selectedName = ""; _isOverwrite = false;
            ResetCaptureState();
            ClearEmployeePanel();
            ClearPreview();
            HideDupProgress();
            btnCapture.Enabled = false;
            btnReset.Enabled   = false;
            SetStatus("Enter employee code and click Lookup to begin.", UiTheme.Info);
            SetInstruction("");
            SetNfiqBadge(0);
            txtEmployeeCode.Focus();
        }

        private void ResetCaptureState() { _captures.Clear(); UpdateCaptureProgress(); }

        // ── Dup progress ──────────────────────────────────────────────────────
        private void ShowDupProgress(int current, int total)
        {
            progressDup.Minimum = 0;
            progressDup.Maximum = Math.Max(total, 1);
            progressDup.Value   = current;
            lblDupProgress.Text = $"Checking: {current} / {total} fingerprints…";
            progressDup.Visible    = true;
            lblDupProgress.Visible = true;
        }
        private void HideDupProgress() { progressDup.Visible = false; lblDupProgress.Visible = false; }

        // ── Quality helpers ───────────────────────────────────────────────────
        private string GetQualityInstruction(int nfiq, int quality)
        {
            if (nfiq == 5)
                return "Poor scan — please put your finger properly and press firmly.\n" +
                       "If skin is dry, moisten your fingertip slightly.";
            if (nfiq == 4)
                return "Fair scan — press harder and ensure full finger contact.\n" +
                       "Align the centre of your fingertip with the sensor.";
            return "Adjust your finger and try again.";
        }

        private string NfiqLabel(int nfiq)
        {
            switch (nfiq)
            {
                case 1: return "Excellent";
                case 2: return "Very Good";
                case 3: return "Good";
                case 4: return "Fair";
                case 5: return "Poor";
                default: return "—";
            }
        }

        private Color NfiqColor(int nfiq)
        {
            switch (nfiq)
            {
                case 1: return UiTheme.Success;
                case 2: return Color.FromArgb(80, 200, 80);
                case 3: return UiTheme.Accent(_deviceType);
                case 4: return UiTheme.Warning;
                default: return UiTheme.Error;
            }
        }

        // Matches MainForm's "Image quality: <band>" wording so the
        // enrollment and punch flows share the same readout style.
        // nfiq == 0 means "no scan yet" — show the muted placeholder
        // (instead of going blank) so the user sees the label exists.
        private void SetNfiqBadge(int nfiq)
        {
            if (nfiq < 1 || nfiq > 5)
            {
                lblNfiqBadge.Text      = "Image quality: —";
                lblNfiqBadge.ForeColor = UiTheme.TextSecondary;
            }
            else
            {
                lblNfiqBadge.Text      = $"Image quality: {NfiqLabel(nfiq)}";
                lblNfiqBadge.ForeColor = NfiqColor(nfiq);
            }
        }

        // ── UI helpers ────────────────────────────────────────────────────────
        private void SetStatus(string msg, Color color)
        {
            Action act = () => { lblStatus.Text = msg; lblStatus.ForeColor = color; };
            if (lblStatus.InvokeRequired) lblStatus.Invoke(act); else act();
        }

        private void SetInstruction(string msg)
        {
            Action act = () => { lblInstruction.Text = msg; };
            if (lblInstruction.InvokeRequired) lblInstruction.Invoke(act); else act();
        }

        private void ShowEmployeePanel(Employee emp)
        {
            lblEmpCode.Text  = emp.EmployeeCode;
            lblEmpName.Text  = emp.FullName;
            lblEmpDept.Text  = string.IsNullOrEmpty(emp.Department) ? "—" : emp.Department;
            lblEmpPhone.Text = string.IsNullOrEmpty(emp.Phone) ? "—" : emp.Phone;
        }

        private void ClearEmployeePanel()
        {
            lblEmpCode.Text = lblEmpName.Text = lblEmpDept.Text = lblEmpPhone.Text = "—";
        }

        private void UpdateCaptureProgress()
        {
            int c = _captures.Count;
            lblProgress.Text = $"{c} / {RequiredCaptures}  quality captures";
            dot1.BackColor   = c >= 1 ? UiTheme.Success : UiTheme.SurfaceLight;
            dot2.BackColor   = c >= 2 ? UiTheme.Success : UiTheme.SurfaceLight;
            dot3.BackColor   = c >= 3 ? UiTheme.Success : UiTheme.SurfaceLight;
        }

        // MFS500 bitmap preview — drop frames when a previous BeginInvoke
        // hasn't been processed yet so the GDI handle queue can't back up
        // during long enrolment sessions.
        private void OnDevicePreview(Bitmap bmp)
        {
            if (bmp == null) return;
            if (picPreview.IsDisposed || _formCts.IsCancellationRequested) { bmp.Dispose(); return; }

            if (System.Threading.Interlocked.CompareExchange(ref _previewBusy, 1, 0) != 0)
            {
                bmp.Dispose();
                return;
            }

            Action update = () =>
            {
                try
                {
                    if (picPreview.IsDisposed) { bmp.Dispose(); return; }
                    var old = picPreview.Image;
                    picPreview.Image = bmp;
                    old?.Dispose();
                }
                catch
                {
                    try { bmp.Dispose(); } catch { }
                }
                finally
                {
                    System.Threading.Interlocked.Exchange(ref _previewBusy, 0);
                }
            };

            try
            {
                if (picPreview.InvokeRequired) picPreview.BeginInvoke(update);
                else                            update();
            }
            catch (ObjectDisposedException)
            {
                bmp.Dispose();
                System.Threading.Interlocked.Exchange(ref _previewBusy, 0);
            }
        }

        // SDK progress / instruction messages (FM220)
        private void OnDeviceProgress(string message)
        {
            SetInstruction(TranslateProgressMessage(message));
        }

        /// <summary>
        /// Map raw SDK strings to user-friendly instructions.
        /// </summary>
        private string TranslateProgressMessage(string sdkMessage)
        {
            if (string.IsNullOrWhiteSpace(sdkMessage)) return "";
            string m = sdkMessage.ToLower();

            // "press harder" and "finger dry" both collapse to "moisten
            // your fingertip" — pressing harder on dry skin doesn't add
            // contact area, the fix is moisture.
            if (m.Contains("place") || m.Contains("put"))
                return "Please place your finger firmly on the sensor.";
            if (m.Contains("still") || m.Contains("hold"))
                return "Hold your finger still — scanning…";
            if (m.Contains("dry") || m.Contains("moisten")
             || m.Contains("press") || m.Contains("harder"))
                return "Moisten your fingertip slightly and try again.";
            if (m.Contains("wet") || m.Contains("too wet"))
                return "Finger too wet — gently dry your fingertip and try again.";
            if (m.Contains("scan"))
                return "Scanning…";
            if (m.Contains("remove") || m.Contains("lift"))
                return "Please remove your finger from the sensor.";

            // Return original message if no match
            return sdkMessage;
        }

        private void ClearPreview()
        {
            if (picPreview.InvokeRequired) { picPreview.Invoke(new Action(ClearPreview)); return; }
            var old = picPreview.Image; picPreview.Image = null; old?.Dispose();
            picPreview.BackColor = UiTheme.Surface;
        }
    }
}
