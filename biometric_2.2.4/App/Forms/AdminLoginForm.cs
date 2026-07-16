using System;
using System.Drawing;
using System.Windows.Forms;
using BiometricAttendance.App.Helpers;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Core.Services;

namespace BiometricAttendance.App.Forms
{
    public partial class AdminLoginForm : Form
    {
        private readonly DeviceType         _deviceType;
        private readonly ApiService         _apiService;
        private readonly AttendanceService  _attendanceService;
        private readonly IFingerprintDevice _device;

        // Re-entry guard: a rapid Enter-Enter (or click + Enter) was previously
        // able to fire btnLogin_Click twice while the first call was still
        // awaiting the HTTP request — leading to two EnrollmentForm.ShowDialog
        // calls and an ObjectDisposedException.
        private bool _loginInProgress;

        public AdminLoginForm(
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
        }

        // ── Theme ─────────────────────────────────────────────────────────────
        private void ApplyTheme()
        {
            this.Text            = "Admin Login  ·  " + UiTheme.DeviceLabel(_deviceType);
            this.BackColor       = UiTheme.Background;
            this.ForeColor       = UiTheme.TextPrimary;
            lblTitle.ForeColor   = UiTheme.Accent(_deviceType);
            lblPrompt.ForeColor  = UiTheme.TextSecondary;
            txtPassword.BackColor= UiTheme.Surface;
            txtPassword.ForeColor= UiTheme.TextPrimary;
            UiTheme.ApplyAccent(btnLogin, _deviceType);
            btnCancel.BackColor  = UiTheme.Surface;
            btnCancel.ForeColor  = UiTheme.TextPrimary;
            btnCancel.FlatAppearance.BorderColor = UiTheme.SurfaceLight;
        }

        // ── Login ─────────────────────────────────────────────────────────────
        private async void btnLogin_Click(object sender, EventArgs e)
        {
            if (_loginInProgress) return;
            _loginInProgress  = true;
            btnLogin.Enabled  = false;
            lblError.ForeColor = UiTheme.Info;
            lblError.Text      = "Verifying…";

            bool authOk = false;
            try
            {
                // Server-side compare via api/admin_login.php — the password
                // never travels back to the client. 401 = wrong password,
                // 429 = rate-limited, 5xx = server problem.
                var pwd    = txtPassword.Text ?? "";
                var result = await _apiService.AdminLoginAsync(pwd.Trim());

                if (result.Success)
                {
                    authOk = true;
                }
                else if (result.StatusCode == 429)
                {
                    lblError.Text      = result.ErrorMessage ?? "Too many attempts — wait a moment.";
                    lblError.ForeColor = UiTheme.Error;
                    txtPassword.Clear();
                    txtPassword.Focus();
                }
                else if (result.StatusCode == 401)
                {
                    lblError.Text      = "Incorrect password.";
                    lblError.ForeColor = UiTheme.Error;
                    txtPassword.Clear();
                    txtPassword.Focus();
                }
                else
                {
                    lblError.Text      = result.ErrorMessage ?? "Login service unavailable.";
                    lblError.ForeColor = UiTheme.Error;
                }
            }
            catch
            {
                lblError.Text      = "Could not verify — check connection.";
                lblError.ForeColor = UiTheme.Error;
            }
            finally
            {
                btnLogin.Enabled  = true;
                _loginInProgress = false;
            }

            if (!authOk) return;

            lblError.Text = "";
            this.Hide();

            using (var enrollForm = new EnrollmentForm(
                _deviceType, _apiService, _attendanceService, _device))
            {
                enrollForm.ShowDialog(this.Owner);
            }

            this.Close();
        }

        private void btnCancel_Click(object sender, EventArgs e) => this.Close();

        private void txtPassword_KeyDown(object sender, KeyEventArgs e)
        {
            if (e.KeyCode == Keys.Enter)
            {
                e.SuppressKeyPress = true;
                // PerformClick respects Enabled, so the disabled button (during
                // an in-flight verify) won't re-fire from the keyboard either.
                if (!_loginInProgress) btnLogin.PerformClick();
            }
        }
    }
}
