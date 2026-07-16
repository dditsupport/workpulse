using BiometricAttendance.App.Helpers;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Core.Models;
using BiometricAttendance.Core.Services;
using System;
using System.Drawing;
using System.IO;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace BiometricAttendance.App.Forms
{
    public partial class MainForm : Form
    {
        private readonly DeviceType _deviceType;
        private readonly IFingerprintDevice _device;
        private readonly AttendanceService _attendanceService;
        private readonly ApiService _apiService;
        private readonly Timer _clockTimer;

        private int _locationId;
        private bool _capturing = false;
        private bool _allowPreview = true;
        private int _previewBusy;       // 0 = idle, 1 = BeginInvoke pending — drop frames otherwise
        public const string AppVersion = "2.2.4";

        public MainForm()
        {
            _deviceType = AppConfig.DeviceType;
            _locationId = AppConfig.LocationId;

            _attendanceService = new AttendanceService();
            _apiService = new ApiService(AppConfig.ApiBaseUrl, AppConfig.ApiKey, _deviceType);

            InitializeComponent();
            ApplyTheme();

            _clockTimer = new Timer { Interval = 1000 };
            _clockTimer.Tick += (s, e) => lblClock.Text = DateTime.Now.ToString("hh:mm:ss tt");
            _clockTimer.Start();

            btnCapture.Click += async (s, e) => await StartCaptureAsync();
            btnSendOtp.Click += async (s, e) => await StartOtpFlowAsync();
            btnAdmin.Click += BtnAdmin_Click;
            btnRefresh.Click += async (s, e) => await SyncAsync();

            txtEmployeeCode.TextChanged += TxtEmployeeCode_Changed;
            txtEmployeeCode.KeyDown += (s, e) =>
            {
                if (e.KeyCode == Keys.Enter)
                { e.SuppressKeyPress = true; LoadEmployeeFromCode(); }
                lblScoreInfo.Text = "";
            };

            _device = DeviceFactory.Create(_deviceType);
            _device.OnPreview += OnDevicePreview;
            // Note: we DON'T subscribe to OnProgressMessage here. The
            // FM220 SDK fires it very sparsely on this hardware (often
            // only once per scan with "place finger"), so the label
            // would look stuck on the first message anyway. Instead the
            // image-quality label stays at its placeholder during the
            // scan and updates to the final NFIQ band the moment
            // CaptureTemplate returns — see the capture loop in
            // StartCaptureAsync. (EnrollmentForm still subscribes for
            // its own instruction label.)

            this.Load += async (s, e) =>
            {
                RotateOldLogs();
                InitDevice();
                await SyncAsync();
            };
        }

        // ── Theme ─────────────────────────────────────────────────────────────
        private void ApplyTheme()
        {
            this.Text = UiTheme.AppTitle(_deviceType);
            this.BackColor = UiTheme.Background;
            this.ForeColor = UiTheme.TextPrimary;

            panelHeader.BackColor = UiTheme.Surface;
            panelBottom.BackColor = UiTheme.Surface;
            panelResult.BackColor = UiTheme.Surface;

            lblTitle.ForeColor = UiTheme.TextPrimary;
            lblDeviceBadge.Text = UiTheme.DeviceLabel(_deviceType);
            lblDeviceBadge.BackColor = UiTheme.Accent(_deviceType);
            lblDeviceBadge.ForeColor = Color.White;
            lblAppVersion.Text = AppVersion;
            lblAppVersion.ForeColor = UiTheme.TextSecondary;
            lblClock.ForeColor = UiTheme.TextPrimary;
            lblCodePrompt.ForeColor = UiTheme.TextSecondary;
            lblEmpName.ForeColor = UiTheme.TextSecondary;
            lblEmpDept.ForeColor = UiTheme.TextSecondary;
            lblPunchStatus.ForeColor = UiTheme.TextSecondary;
            lblDeviceStatus.ForeColor = UiTheme.TextSecondary;
            lblDeviceSerial.ForeColor = UiTheme.TextSecondary;
            lblStatus.ForeColor = UiTheme.Info;
            lblResultIcon.ForeColor = UiTheme.TextSecondary;
            lblResultText.ForeColor = UiTheme.TextSecondary;
            lblResultSub.ForeColor = UiTheme.TextSecondary;
            lblPrevPunch.ForeColor = UiTheme.TextSecondary;
            lblScoreInfo.ForeColor = UiTheme.TextSecondary;

            txtEmployeeCode.BackColor = UiTheme.Surface;
            txtEmployeeCode.ForeColor = UiTheme.TextPrimary;
            picPreview.BackColor = UiTheme.Surface;

            UiTheme.ApplyAccent(btnCapture, _deviceType);
            UiTheme.ApplyAccent(btnSendOtp, _deviceType);

            btnRefresh.BackColor = UiTheme.Surface; btnRefresh.ForeColor = UiTheme.TextSecondary;
            btnRefresh.FlatAppearance.BorderColor = UiTheme.SurfaceLight;
            btnAdmin.BackColor = UiTheme.Surface; btnAdmin.ForeColor = UiTheme.TextSecondary;
            btnAdmin.FlatAppearance.BorderColor = UiTheme.SurfaceLight;

            btnCapture.Enabled = false;
            btnSendOtp.Visible = false;
        }

        // ── Device init ───────────────────────────────────────────────────────
        private void InitDevice()
        {
            try
            {
                _device.Initialize();
                SetDeviceStatus("Ready", UiTheme.Success);
                lblDeviceSerial.Text = _device.DeviceSerial;
            }
            catch (Exception ex)
            {
                SetDeviceStatus("Device Error", UiTheme.Error);
                MessageBox.Show(
                    $"Could not initialize {UiTheme.DeviceLabel(_deviceType)}:\n\n{ex.Message}",
                    "Device Error", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
        }

        // ── Startup sync ──────────────────────────────────────────────────────
        private async Task SyncAsync()
        {
            SetStatus("Syncing with server…", UiTheme.Info);
            btnRefresh.Enabled = false;

            try
            {
                var empTask = _apiService.GetEmployeesAsync();
                var settingsTask = _apiService.GetSystemSettingsAsync();
                var lastPunchTask = _apiService.GetLastPunchesAsync(_device?.DeviceSerial ?? "");
                await Task.WhenAll(empTask, settingsTask, lastPunchTask);

                // Use await on each task (not .Result) so the original
                // exception bubbles cleanly instead of an AggregateException.
                var employees   = await empTask;
                var settings    = await settingsTask;
                var lastPunches = await lastPunchTask;

                int interval = 0; int? gt = null, et = null;
                if (settings.TryGetValue("MinPunchIntervalMinutes", out string iv)) int.TryParse(iv, out interval);
                if (settings.TryGetValue("MatchThreshold_Attendance", out string at) && int.TryParse(at, out int atv)) gt = atv;
                if (settings.TryGetValue("MatchThreshold_EnrollDuplicate", out string ed) && int.TryParse(ed, out int edv)) et = edv;
                if (settings.TryGetValue("ShiftCutoffHour", out string sc)) ShiftHelper.SetCutoffHour(sc);

                _attendanceService.ApplyServerSettings(interval, gt, et);

                foreach (var emp in employees)
                    if (lastPunches.TryGetValue(emp.EmployeeCode, out var lp))
                    { emp.LastPunchTime = lp.time; emp.LastPunchType = lp.type; }

                _attendanceService.LoadEmployees(employees);

                // ── Report running app version to server (no-op if unchanged) ────────
                await _apiService.ReportAppVersionAsync(_device.DeviceSerial, AppVersion);  //update version when new comment received.

                SetStatus($"Ready . {employees.Count} | {ShiftHelper.ShiftCutoffHours}", UiTheme.Success);
            }
            catch (Exception ex)
            {
                LogError(ex);
                SetStatus("Sync failed: " + ex.Message, UiTheme.Error);
            }
            finally { btnRefresh.Enabled = true; }
        }

        // ── Employee code input ───────────────────────────────────────────────
        private void TxtEmployeeCode_Changed(object sender, EventArgs e)
        {
            if (txtEmployeeCode.Text.Length >= 3) LoadEmployeeFromCode();
        }

        private void LogError(Exception ex)
        {
            try
            {
                string logDirectory = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "Logs");

                // Ensure folder exists
                if (!Directory.Exists(logDirectory))
                    Directory.CreateDirectory(logDirectory);

                // File name based on current date
                string filePath = Path.Combine(
                    logDirectory,
                    $"log_{DateTime.Now:yyyy-MM-dd}.txt"
                );

                // Build log entry
                string logEntry =
                    "==================================================\n" +
                    $"Date: {DateTime.Now:yyyy-MM-dd HH:mm:ss}\n" +
                    $"Message: {ex.Message}\n" +
                    $"StackTrace:\n{ex.StackTrace}\n" +
                    $"InnerException:\n{ex.InnerException}\n" +
                    "==================================================\n\n";

                // Create file if not exists, otherwise append
                File.AppendAllText(filePath, logEntry);
            }
            catch
            {
                // Never throw from logger
            }
        }

        private void LoadEmployeeFromCode()
        {
            string code = txtEmployeeCode.Text.Trim().ToUpper();
            if (string.IsNullOrEmpty(code)) { ClearEmployeePanel(); return; }

            var emp = _attendanceService.GetByCode(code);
            if (emp == null)
            {
                lblEmpName.Text = "Employee not found"; lblEmpName.ForeColor = UiTheme.Error;
                lblEmpDept.Text = ""; lblPunchStatus.Text = "";
                btnCapture.Enabled = false; btnSendOtp.Visible = false;
                return;
            }

            if (!emp.HasTemplate(_deviceType))
            {
                lblEmpName.Text = emp.FullName;
                lblEmpName.ForeColor = UiTheme.Warning;
                lblEmpDept.Text = emp.Department ?? "";
                lblPunchStatus.Text = $"No {UiTheme.DeviceLabel(_deviceType)} template enrolled";
                btnCapture.Enabled = false;
                btnSendOtp.Visible = emp.OtpEnabled;
                return;
            }

            lblEmpName.Text = emp.FullName;
            lblEmpName.ForeColor = UiTheme.TextPrimary;
            lblEmpDept.Text = emp.Department ?? "";

            if (emp.LastPunchTime.HasValue)
            {
                string when = emp.LastPunchTime.Value.Date == DateTime.Today
                    ? emp.LastPunchTime.Value.ToString("hh:mm tt")
                    : emp.LastPunchTime.Value.ToString("dd MMM  hh:mm tt");
                lblPunchStatus.Text = $"Last: {emp.LastPunchType}  at  {when}";
                lblPunchStatus.ForeColor = emp.LastPunchType == "IN" ? UiTheme.Success : UiTheme.Info;
            }
            else
            {
                lblPunchStatus.Text = "No punch today"; lblPunchStatus.ForeColor = UiTheme.TextSecondary;
            }

            btnCapture.Enabled = true;
            btnSendOtp.Visible = emp.OtpEnabled;
        }

        private void ClearEmployeePanel()
        {
            lblEmpName.Text = "—"; lblEmpName.ForeColor = UiTheme.TextSecondary;
            lblEmpDept.Text = ""; lblPunchStatus.Text = "";
            btnCapture.Enabled = false; btnSendOtp.Visible = false;
        }

        // ── Fingerprint punch ─────────────────────────────────────────────────

        // NFIQ 1–3 = Excellent / Very Good / Good → acceptable for matching.
        // NFIQ 4–5 = Fair / Poor → image is too degraded; ask user to re-place.
        private const int MaxCaptureAttempts = 3;
        private const int AcceptableNfiq = 3;   // inclusive upper bound
        private const int Mfs500PreviewSeconds = 5;  // countdown before MFS500 captures
        private const int MinProbeMinutiae = 12;  // FM220 probe floor; tune from match_sdk_error rates

        private async Task StartCaptureAsync()
        {
            if (_capturing) return;

            string code = txtEmployeeCode.Text.Trim().ToUpper();
            var emp = _attendanceService.GetByCode(code);
            if (emp == null) { SetStatus("Employee not found.", UiTheme.Error); return; }

            string punchType = DeterminePunchType(emp);
            string valErr = _attendanceService.ValidatePunch(emp, punchType);
            if (valErr != null)
            {
                ShowPunchResult(false, punchType, emp, 0, 0, false);
                SetResultSub(valErr);
                BlankCodeField();
                return;
            }

            _capturing = true; _allowPreview = true;
            btnCapture.Enabled = false; btnSendOtp.Visible = false;

            if (_deviceType == DeviceType.FM220)
                _device.SetPreviewHandle(picPreview.Handle);

            try
            {
                CaptureResult? result = null;
                int attempt = 0;
                bool goodNfiq = false;

                // ── Quality-gated capture loop ────────────────────────────────
                while (attempt < MaxCaptureAttempts)
                {
                    attempt++;

                    string attemptHint = MaxCaptureAttempts > 1
                        ? $"  (attempt {attempt}/{MaxCaptureAttempts})"
                        : "";

                    // ── MFS500: continuous preview loop during countdown ───────
                    // AutoCapture returns as soon as quality threshold is met —
                    // after that OnPreview stops firing and the preview freezes.
                    // If the user adjusts their finger, the SDK is idle so no new
                    // frames arrive. Fix: restart CaptureTemplate every time it
                    // completes during the countdown window, discarding each result.
                    // Each restart causes the SDK to scan again → OnPreview fires
                    // again → user sees their adjusted finger position live.
                    if (_deviceType == DeviceType.MFS500)
                    {
                        var countdownEnd = DateTime.Now.AddSeconds(Mfs500PreviewSeconds);

                        while (DateTime.Now < countdownEnd && _capturing)
                        {
                            // Update countdown label every 500 ms
                            int secsLeft = Math.Max(1, (int)(countdownEnd - DateTime.Now).TotalSeconds);
                            SetStatus(
                                $"Place finger to preview — capturing in {secsLeft}s{attemptHint}",
                                UiTheme.Accent(_deviceType));
                            ShowScanningState(punchType, emp);

                            // Run one capture cycle for preview purposes
                            var previewTask = Task.Run(() => _device.CaptureTemplate());

                            // Tick every 500 ms to keep countdown label fresh;
                            // exit inner loop when task finishes OR time runs out
                            while (!previewTask.IsCompleted && DateTime.Now < countdownEnd && _capturing)
                            {
                                await Task.Delay(500);
                                secsLeft = Math.Max(1, (int)(countdownEnd - DateTime.Now).TotalSeconds);
                                SetStatus(
                                    $"Place finger to preview — capturing in {secsLeft}s{attemptHint}",
                                    UiTheme.Accent(_deviceType));
                            }

                            // Always await to ensure SDK is idle before next call
                            await previewTask;

                            // If countdown still running: discard result, restart loop
                            // so SDK begins scanning again → fresh preview frames fire
                            // If countdown expired: exit and do the real capture below
                        }

                        if (!_capturing) return;

                        // Countdown done — user has positioned their finger.
                        // Do the actual capture that will be used for matching.
                        SetStatus($"Hold still — capturing…{attemptHint}", UiTheme.Accent(_deviceType));
                        result = await Task.Run(() => _device.CaptureTemplate());
                    }
                    else
                    {
                        SetStatus($"Place finger firmly on sensor…{attemptHint}",
                                  UiTheme.Accent(_deviceType));
                        ShowScanningState(punchType, emp);
                        result = await Task.Run(() => _device.CaptureTemplate());
                    }

                    if (result == null)
                    {
                        // Timeout on this attempt — stop the loop, let the user
                        // press Capture again themselves.
                        SetStatus("Timeout — try again.", UiTheme.Warning);
                        btnCapture.Enabled = true;
                        btnSendOtp.Visible = emp.OtpEnabled;
                        return;
                    }

                    int nfiq = result.Value.Nfiq;

                    if (nfiq <= AcceptableNfiq)
                    {
                        // NFIQ rates IMAGE quality, not how many minutiae were actually
                        // extracted. On FM220 a light/partial press can pass NFIQ yet
                        // yield too few minutiae for the matcher, which then returns an
                        // error status (logged as match_sdk_error). Gate on the probe's
                        // own minutiae count so we re-prompt BEFORE matching.
                        if (_deviceType == DeviceType.FM220 &&
                            ScoreHelper.TryGetIsoMinutiaeCount(result.Value.Template, out int minutiae) &&
                            minutiae < MinProbeMinutiae)
                        {
                            if (attempt < MaxCaptureAttempts)
                            {
                                SetStatus(
                                    $"Partial fingerprint — press your whole finger flat and try again. " +
                                    $"({minutiae}/{MinProbeMinutiae} points)",
                                    UiTheme.Warning);
                                await Task.Delay(1200);
                            }
                            continue; // retry; do not break, do not fall through to the NFIQ message
                        }

                        // Quality + minutiae acceptable — proceed to match
                        goodNfiq = true;
                        break;
                    }

                    // Quality too low — tell the user and retry if attempts remain
                    string qualityLabel = ScoreHelper.NfiqLabel(nfiq);   // "Fair" or "Poor"
                    if (attempt < MaxCaptureAttempts)
                    {
                        SetStatus(
                            $"Image quality {qualityLabel} (NFIQ {nfiq}) — adjust finger and try again.",
                            UiTheme.Warning);

                        // Brief pause so the status message is readable before
                        // the next capture instruction replaces it.
                        await Task.Delay(1200);
                    }
                }

                if (!goodNfiq)
                {
                    // All attempts exhausted with poor quality every time
                    int lastNfiq = result.Value.Nfiq;
                    SetStatus(
                        $"Could not capture clear image after {MaxCaptureAttempts} attempt(s). " +
                        $"Last quality: {ScoreHelper.NfiqLabel(lastNfiq)} (NFIQ {lastNfiq}). Try again.",
                        UiTheme.Error);
                    btnCapture.Enabled = true;
                    btnSendOtp.Visible = emp.OtpEnabled;
                    return;
                }

                // ── Quality passed — run 1:1 match ────────────────────────────
                var capture = result.Value;
                int threshold = _attendanceService.GetEffectiveThreshold(emp);

                int rawScore;
                try
                {
                    rawScore = _device.Match(capture.Template, emp.GetTemplate(_deviceType));
                }
                catch (FingerprintMatchException fmx)
                {
                    // Matcher errored — NOT a low score. Log distinctly with the SDK
                    // code and a NULL match_score, then let the user re-scan or fall
                    // back to OTP.
                    await _apiService.SendFailedPunchAsync(
                        emp.EmployeeCode, _device.DeviceSerial, _locationId,
                        punchType, "fingerprint", null, threshold,
                        "match_sdk_error:" + fmx.StatusCode);

                    MessageBox.Show(
                        "Finger Identification Failed.\n\nPlease scan again"
                            + (emp.OtpEnabled ? ", or use OTP." : "."),
                        "Attendance",
                        MessageBoxButtons.OK,
                        MessageBoxIcon.Warning);

                    btnCapture.Enabled = true;
                    btnSendOtp.Visible = emp.OtpEnabled;
                    BlankCodeField();
                    return;
                }

                int normScore = ScoreHelper.Normalize(rawScore, _deviceType);

                if (normScore >= threshold)
                {
                    await ProcessSuccessfulPunchAsync(
                        emp, punchType, normScore, capture.Quality, capture.Nfiq, "fingerprint");
                }
                else
                {
                    ShowPunchResult(false, punchType, emp, normScore, capture.Nfiq, false, threshold);
                    await _apiService.SendFailedPunchAsync(
                        emp.EmployeeCode, _device.DeviceSerial, _locationId,
                        punchType, "fingerprint", normScore, threshold, "score_below_threshold");
                    SetStatus("Ready", UiTheme.TextSecondary);
                    BlankCodeField();
                }
            }
            catch (Exception ex)
            {
                SetStatus("Capture error: " + ex.Message, UiTheme.Error);
                btnCapture.Enabled = true;
                btnSendOtp.Visible = emp?.OtpEnabled == true;
            }
            finally { _capturing = false; _allowPreview = false; }
        }

        // ── OTP punch ─────────────────────────────────────────────────────────
        private async Task StartOtpFlowAsync()
        {
            string code = txtEmployeeCode.Text.Trim().ToUpper();
            var emp = _attendanceService.GetByCode(code);
            if (emp == null || !emp.OtpEnabled) return;

            string punchType = DeterminePunchType(emp);
            string valErr = _attendanceService.ValidatePunch(emp, punchType);
            if (valErr != null)
            {
                ShowPunchResult(false, punchType, emp, 0, 0, true);
                SetResultSub(valErr);
                BlankCodeField();
                return;
            }

            SetStatus("Sending OTP…", UiTheme.Info);
            btnSendOtp.Enabled = false; btnCapture.Enabled = false;

            var sendResult = await _apiService.SendOtpAsync(
                emp.EmployeeCode, _device.DeviceSerial, _locationId, punchType);

            if (!sendResult.Success)
            {
                SetStatus("OTP send failed: " + sendResult.ErrorMessage, UiTheme.Error);
                btnSendOtp.Enabled = true; btnCapture.Enabled = emp.HasTemplate(_deviceType);
                return;
            }

            SetStatus($"OTP sent to {sendResult.MaskedTo}", UiTheme.Success);

            using (var otpForm = new OtpPunchForm(
                _deviceType, _apiService,
                emp.EmployeeCode, _device.DeviceSerial,
                _locationId, punchType, sendResult))
            {
                if (otpForm.ShowDialog(this) == DialogResult.OK && otpForm.OtpVerified)
                    await ProcessSuccessfulPunchAsync(emp, punchType, 100, 0, 0, "otp");
                else
                {
                    SetStatus("OTP verification cancelled.", UiTheme.Warning);
                    btnSendOtp.Enabled = true;
                    btnCapture.Enabled = emp.HasTemplate(_deviceType);
                }
            }
        }

        // ── Successful punch ──────────────────────────────────────────────────

        private async Task ProcessSuccessfulPunchAsync(
            Employee emp, string punchType,
            int normScore, int quality, int nfiq, string punchMethod)
        {
            int threshold = _attendanceService.GetEffectiveThreshold(emp);

            ApiResult saveResult = await _apiService.SendAttendanceAsync(
                emp.EmployeeCode, _device.DeviceSerial,
                _locationId, punchType, punchMethod, normScore);

            if (saveResult.Success)
            {
                _attendanceService.RegisterPunch(
                    emp, punchType, normScore,
                    _device.DeviceSerial, _deviceType, _locationId, punchMethod);

                ShowPunchResult(true, punchType, emp, normScore, nfiq, punchMethod == "otp", threshold);
                SetStatus("Ready", UiTheme.Success);
                BlankCodeField();
            }
            else
            {
                SetStatus(saveResult.ErrorMessage ?? "Server save failed — check connection.", UiTheme.Error);
                btnCapture.Enabled = emp.HasTemplate(_deviceType);
                btnSendOtp.Visible = emp.OtpEnabled;
            }
        }

        // ── Punch type ────────────────────────────────────────────────────────
        // Uses a 4AM shift-day cutoff to match the server (see attendance.php).
        // A shift that ends at 04:00 crosses midnight — comparing calendar dates
        // would mis-classify a 00:04 punch as a new day and force IN again.
        private string DeterminePunchType(Employee emp)
        {
            if (!emp.LastPunchTime.HasValue) return "IN";
            if (ShiftHelper.ShiftDay(emp.LastPunchTime.Value) != ShiftHelper.ShiftDay(DateTime.Now))
                return "IN";
            if (emp.LastPunchType == "IN") return "OUT";
            return "IN";
        }

        // ── Blank code field only (NOT employee info or result) ───────────────
        private void BlankCodeField()
        {
            Action act = () =>
            {
                txtEmployeeCode.Clear();
                // Do NOT clear employee labels — result stays visible
                // Next keypress in txtEmployeeCode will load new employee
            };
            if (this.InvokeRequired) this.BeginInvoke(act); else act();
        }

        // =====================================================================
        // UI STATE — result panel
        // =====================================================================
        private void ShowScanningState(string punchType, Employee emp)
        {
            panelResult.BackColor = UiTheme.SurfaceLight;
            lblResultIcon.Text = "◎";
            lblResultIcon.ForeColor = UiTheme.Accent(_deviceType);
            lblResultText.Text = $"{punchType}  SCANNING";
            lblResultText.ForeColor = UiTheme.Accent(_deviceType);
            lblResultSub.Text = "Place finger on sensor";
            lblResultSub.ForeColor = UiTheme.TextSecondary;
            SetPrevPunchLabel(emp);
            lblScoreInfo.Text = "";
        }

        /// <summary>
        /// Shows punch result with:
        ///   - Previous punch (before this attempt)
        ///   - Score value and NFIQ text on one line
        /// No progress bar — just text.
        /// Employee info labels are NOT touched here.
        /// </summary>
        private void ShowPunchResult(
            bool success, string punchType, Employee emp,
            int normScore, int nfiq, bool isOtp, int threshold = 0) //threshold added to show in result sub when score below threshold
        {
            panelResult.BackColor = success ? Color.FromArgb(18, 58, 32) : Color.FromArgb(68, 18, 18);
            lblResultIcon.Text = success ? "✓" : "✗";
            lblResultIcon.ForeColor = success ? UiTheme.Success : UiTheme.Error;
            lblResultText.Text = success ? $"{punchType}  GRANTED" : $"{punchType}  DENIED";
            lblResultText.ForeColor = success ? UiTheme.Success : UiTheme.Error;
            lblResultSub.Text = success
                ? DateTime.Now.ToString("hh:mm:ss tt")
                : "Score below threshold";
            lblResultSub.ForeColor = UiTheme.TextSecondary;

            SetPrevPunchLabel(emp);

            // Score + NFIQ on one line — no progress bar
            if (isOtp)
            {
                lblScoreInfo.Text = "Method: OTP";
                lblScoreInfo.ForeColor = UiTheme.Info;
            }
            else //if (threshold > 0)
            {
                string nfiqText = nfiq > 0
                    ? $"  |  NFIQ: {nfiq} — {ScoreHelper.NfiqLabel(nfiq)}"
                    : "";
                //lblScoreInfo.Text = $"Score: {normScore}/{threshold}/100  |  {ScoreHelper.ScoreLabel(normScore)}{nfiqText}";
                lblScoreInfo.Text = $"Score: {normScore}/100  |  Threshold: {threshold}  |  {ScoreHelper.ScoreLabel(normScore)}{nfiqText}";
                lblScoreInfo.ForeColor = success ? UiTheme.Success : UiTheme.Error;
            }/*
            else
            {
                // Validation errors and other no-scan paths: nothing to display.
                lblScoreInfo.Text = "";
            }*/

        }

        private void SetResultSub(string msg) => lblResultSub.Text = msg;

        private void SetPrevPunchLabel(Employee emp)
        {
            if (emp?.LastPunchTime.HasValue == true)
            {
                string when = emp.LastPunchTime.Value.Date == DateTime.Today
                    ? emp.LastPunchTime.Value.ToString("hh:mm tt")
                    : emp.LastPunchTime.Value.ToString("dd MMM  hh:mm tt");
                lblPrevPunch.Text = $"← Previous: {emp.LastPunchType} at {when}";
                lblPrevPunch.ForeColor = emp.LastPunchType == "IN" ? UiTheme.Success : UiTheme.Info;
            }
            else
            {
                lblPrevPunch.Text = "← No previous punch today";
                lblPrevPunch.ForeColor = UiTheme.TextSecondary;
            }
        }

        private void SetStatus(string msg, Color color)
        {
            Action act = () => { lblStatus.Text = msg; lblStatus.ForeColor = color; };
            if (lblStatus.InvokeRequired) lblStatus.BeginInvoke(act); else act();
        }

        private void SetDeviceStatus(string msg, Color color)
        { lblDeviceStatus.Text = msg; lblDeviceStatus.ForeColor = color; }

        private void OnDevicePreview(Bitmap bmp)
        {
            if (bmp == null) return;
            if (!_allowPreview || picPreview.IsDisposed) { bmp.Dispose(); return; }

            // Drop frames if a previous BeginInvoke hasn't finished yet —
            // prevents the GDI handle queue from backing up under load.
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
                    var old = picPreview.Image; picPreview.Image = bmp; old?.Dispose();
                }
                catch { try { bmp.Dispose(); } catch { } }
                finally { System.Threading.Interlocked.Exchange(ref _previewBusy, 0); }
            };

            try
            {
                if (picPreview.InvokeRequired) picPreview.BeginInvoke(update);
                else                           update();
            }
            catch (ObjectDisposedException)
            {
                bmp.Dispose();
                System.Threading.Interlocked.Exchange(ref _previewBusy, 0);
            }
        }

        private void ClearPreview()
        {
            var old = picPreview.Image; picPreview.Image = null; old?.Dispose();
            picPreview.BackColor = UiTheme.Surface;
        }

        // ── Admin ─────────────────────────────────────────────────────────────
        private void BtnAdmin_Click(object sender, EventArgs e)
        {
            using (var loginForm = new AdminLoginForm(_deviceType, _apiService, _attendanceService, _device))
                loginForm.ShowDialog(this);
            _ = SyncAsync();
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            _clockTimer.Stop(); _clockTimer.Dispose();
            _device.OnPreview -= OnDevicePreview;
            try { _device.Shutdown(); }
            catch (Exception shutdownEx)
            {
                // Don't block app close, but log so the "works once, fails on restart"
                // FM220 SDK pattern is at least diagnosable.
                LogError(shutdownEx);
            }
            _device.Dispose();
            _apiService.Dispose();
            ClearPreview();
            base.OnFormClosing(e);
        }

        // Delete log files older than 30 days. Best-effort: silent failures
        // are fine, but we surface the rotation error once so the operator
        // notices if the Logs folder is read-only.
        private static bool _logRotationDone;
        private void RotateOldLogs()
        {
            if (_logRotationDone) return;
            _logRotationDone = true;
            try
            {
                string logDirectory = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "Logs");
                if (!Directory.Exists(logDirectory)) return;
                var cutoff = DateTime.Now.AddDays(-30);
                foreach (var f in Directory.GetFiles(logDirectory, "log_*.txt"))
                {
                    try { if (File.GetLastWriteTime(f) < cutoff) File.Delete(f); }
                    catch { /* per-file failures are fine */ }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("Log rotation failed: " + ex.Message);
            }
        }
    }
}