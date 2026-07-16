using BiometricAttendance.App.Helpers;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Core.Services;
using System;
using System.Drawing;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace BiometricAttendance.App.Forms
{
    public partial class OtpPunchForm : Form
    {
        private const int MaxAttempts = 3;

        private readonly DeviceType    _deviceType;
        private readonly ApiService    _apiService;
        private readonly string        _employeeCode;
        private readonly string        _deviceSerial;
        private readonly int           _locationId;
        private readonly string        _punchType;
        private readonly OtpSendResult _sendResult;

        private readonly Timer _countdown;
        private DateTime?      _expiresAt;
        private int            _failedAttempts;
        private bool           _verifyInProgress;

        /// <summary>True if OTP was verified successfully.</summary>
        public bool OtpVerified { get; private set; }

        public OtpPunchForm(
            DeviceType    deviceType,
            ApiService    apiService,
            string        employeeCode,
            string        deviceSerial,
            int           locationId,
            string        punchType,
            OtpSendResult sendResult)
        {
            _deviceType   = deviceType;
            _apiService   = apiService;
            _employeeCode = employeeCode;
            _deviceSerial = deviceSerial;
            _locationId   = locationId;
            _punchType    = punchType;
            _sendResult   = sendResult;

            InitializeComponent();
            ApplyTheme();
            PopulateInfo();

            // Anchor countdown to server clock when available; fall back to
            // local now() + ExpiresIn (less accurate but better than nothing).
            _expiresAt = sendResult.ExpiresAt
                ?? DateTime.Now.AddMinutes(Math.Max(1, sendResult.ExpiresIn));

            _countdown = new Timer { Interval = 1000 };
            _countdown.Tick += Countdown_Tick;
            _countdown.Start();

            this.ActiveControl = txtOtp;
        }

        // ── Theme ─────────────────────────────────────────────────────────────
        private void ApplyTheme()
        {
            this.BackColor       = UiTheme.Background;
            this.ForeColor       = UiTheme.TextPrimary;
            lblTitle.ForeColor   = UiTheme.TextPrimary;
            lblInfo.ForeColor    = UiTheme.TextSecondary;
            lblExpiry.ForeColor  = UiTheme.Warning;
            lblOtpHint.ForeColor = UiTheme.TextSecondary;
            txtOtp.BackColor     = UiTheme.Surface;
            txtOtp.ForeColor     = UiTheme.TextPrimary;
            UiTheme.ApplyAccent(btnVerify, _deviceType);
            btnCancel.BackColor  = UiTheme.Surface;
            btnCancel.ForeColor  = UiTheme.TextPrimary;
            btnCancel.FlatAppearance.BorderColor = UiTheme.SurfaceLight;
        }

        private void PopulateInfo()
        {
            string channel = _sendResult.Channel == "sms" ? "SMS" : "Email";
            lblInfo.Text   = $"OTP sent via {channel} to {_sendResult.MaskedTo}";
            lblExpiry.Text = $"Valid for {_sendResult.ExpiresIn} minutes";
        }

        private void Countdown_Tick(object sender, EventArgs e)
        {
            if (!_expiresAt.HasValue) return;

            var remaining = _expiresAt.Value - DateTime.Now;
            if (remaining.TotalSeconds <= 0)
            {
                _countdown.Stop();
                lblExpiry.Text      = "OTP expired — request a new one.";
                lblExpiry.ForeColor = UiTheme.Error;
                btnVerify.Enabled   = false;
                return;
            }

            int mins = (int)remaining.TotalMinutes;
            int secs = remaining.Seconds;
            lblExpiry.Text = $"Expires in {mins:D2}:{secs:D2}";
        }

        // ── Verify ────────────────────────────────────────────────────────────
        private async void btnVerify_Click(object sender, EventArgs e)
        {
            if (_verifyInProgress) return;

            string otp = (txtOtp.Text ?? "").Trim();

            // Match server's expected length (configurable on the server, but
            // 6 digits is the new default; allow 4–10 to stay forgiving).
            if (!Regex.IsMatch(otp, @"^\d{4,10}$"))
            {
                ShowError("Please enter the digits sent to you.");
                return;
            }

            _verifyInProgress = true;
            btnVerify.Enabled  = false;
            btnCancel.Enabled  = false;
            lblStatus.ForeColor = UiTheme.Info;
            lblStatus.Text      = "Verifying…";

            try
            {
                var result = await _apiService.VerifyOtpAsync(
                    _employeeCode, otp, _deviceSerial, _locationId, _punchType);

                if (result.Success)
                {
                    OtpVerified         = true;
                    lblStatus.Text      = "OTP Verified ✓";
                    lblStatus.ForeColor = UiTheme.Success;
                    _countdown.Stop();
                    await Task.Delay(400);
                    this.DialogResult = DialogResult.OK;
                    this.Close();
                    return;
                }

                if (result.Locked)
                {
                    _countdown.Stop();
                    ShowError(result.ErrorMessage ?? "Locked — request a new OTP.");
                    btnVerify.Enabled = false;
                    btnCancel.Enabled = true;
                    return;
                }

                _failedAttempts++;
                int remaining = Math.Max(0, MaxAttempts - _failedAttempts);
                if (_failedAttempts >= MaxAttempts)
                {
                    ShowError("Too many wrong tries — request a new OTP.");
                    btnVerify.Enabled = false;
                }
                else
                {
                    ShowError($"Invalid OTP. {remaining} attempt(s) left.");
                    btnVerify.Enabled = true;
                }
                btnCancel.Enabled = true;
                txtOtp.Clear();
                txtOtp.Focus();
            }
            catch (Exception ex)
            {
                ShowError("Network error — please try again.");
                System.Diagnostics.Debug.WriteLine("VerifyOtp threw: " + ex.Message);
                btnVerify.Enabled = true;
                btnCancel.Enabled = true;
            }
            finally
            {
                _verifyInProgress = false;
            }
        }

        private void btnCancel_Click(object sender, EventArgs e)
        {
            OtpVerified       = false;
            this.DialogResult = DialogResult.Cancel;
            this.Close();
        }

        private void txtOtp_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.Enter)
            {
                e.SuppressKeyPress = true;
                if (!_verifyInProgress && btnVerify.Enabled) btnVerify.PerformClick();
            }
        }

        protected override void OnFormClosed(FormClosedEventArgs e)
        {
            try { _countdown?.Stop(); _countdown?.Dispose(); }
            catch { }
            base.OnFormClosed(e);
        }

        private void ShowError(string msg)
        {
            lblStatus.Text      = msg;
            lblStatus.ForeColor = UiTheme.Error;
        }
    }
}
