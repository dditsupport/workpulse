namespace BiometricAttendance.App.Forms
{
    partial class MainForm
    {
        private System.ComponentModel.IContainer components = null;

        protected override void Dispose(bool disposing)
        {
            if (disposing && (components != null)) components.Dispose();
            base.Dispose(disposing);
        }

        #region Windows Form Designer generated code

        private void InitializeComponent()
        {
            this.panelHeader     = new System.Windows.Forms.Panel();
            this.lblTitle        = new System.Windows.Forms.Label();
            this.lblDeviceBadge  = new System.Windows.Forms.Label();
            this.lblAppVersion   = new System.Windows.Forms.Label();
            this.lblClock        = new System.Windows.Forms.Label();
            this.lblCodePrompt   = new System.Windows.Forms.Label();
            this.txtEmployeeCode = new System.Windows.Forms.TextBox();
            this.lblEmpName      = new System.Windows.Forms.Label();
            this.lblEmpDept      = new System.Windows.Forms.Label();
            this.lblPunchStatus  = new System.Windows.Forms.Label();
            this.btnCapture      = new System.Windows.Forms.Button();
            this.btnSendOtp      = new System.Windows.Forms.Button();
            this.picPreview      = new System.Windows.Forms.PictureBox();
            this.panelResult     = new System.Windows.Forms.Panel();
            this.lblResultIcon   = new System.Windows.Forms.Label();
            this.lblResultText   = new System.Windows.Forms.Label();
            this.lblResultSub    = new System.Windows.Forms.Label();
            this.lblPrevPunch    = new System.Windows.Forms.Label();
            this.lblScoreInfo    = new System.Windows.Forms.Label();
            this.panelBottom     = new System.Windows.Forms.Panel();
            this.lblDeviceStatus = new System.Windows.Forms.Label();
            this.lblDeviceSerial = new System.Windows.Forms.Label();
            this.lblStatus       = new System.Windows.Forms.Label();
            this.btnRefresh      = new System.Windows.Forms.Button();
            this.btnAdmin        = new System.Windows.Forms.Button();
            this.panelHeader.SuspendLayout();
            this.panelResult.SuspendLayout();
            this.panelBottom.SuspendLayout();
            ((System.ComponentModel.ISupportInitialize)(this.picPreview)).BeginInit();
            this.SuspendLayout();

            // ── Form ──────────────────────────────────────────────────────────
            this.ClientSize      = new System.Drawing.Size(780, 540);
            this.FormBorderStyle = System.Windows.Forms.FormBorderStyle.FixedSingle;
            this.MaximizeBox     = false;
            this.StartPosition   = System.Windows.Forms.FormStartPosition.CenterScreen;
            this.Font            = new System.Drawing.Font("Segoe UI", 9.5f);
            this.Name            = "MainForm";
            this.Text            = "Biometric Attendance";
            this.Icon            = System.Drawing.Icon.ExtractAssociatedIcon(System.Reflection.Assembly.GetExecutingAssembly().Location);

            // ── panelHeader ───────────────────────────────────────────────────
            this.panelHeader.Name     = "panelHeader";
            this.panelHeader.Location = new System.Drawing.Point(0, 0);
            this.panelHeader.Size     = new System.Drawing.Size(780, 58);
            this.panelHeader.TabIndex = 0;

            this.lblTitle.Name      = "lblTitle";
            this.lblTitle.Text      = "BIOMETRIC ATTENDANCE";
            this.lblTitle.Font      = new System.Drawing.Font("Segoe UI", 14f, System.Drawing.FontStyle.Bold);
            this.lblTitle.AutoSize  = true;
            this.lblTitle.Location  = new System.Drawing.Point(16, 14);
            this.lblTitle.TabIndex  = 0;

            this.lblDeviceBadge.Name      = "lblDeviceBadge";
            this.lblDeviceBadge.AutoSize  = false;
            this.lblDeviceBadge.Size      = new System.Drawing.Size(72, 26);
            this.lblDeviceBadge.Location  = new System.Drawing.Point(270, 16);
            this.lblDeviceBadge.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblDeviceBadge.Font      = new System.Drawing.Font("Segoe UI", 8.5f, System.Drawing.FontStyle.Bold);
            this.lblDeviceBadge.TabIndex  = 1;

            this.lblAppVersion.Name      = "lblAppVersion";
            this.lblAppVersion.AutoSize  = true;
            this.lblAppVersion.Location  = new System.Drawing.Point(348, 20);
            this.lblAppVersion.Font      = new System.Drawing.Font("Segoe UI", 8.5f, System.Drawing.FontStyle.Regular);
            this.lblAppVersion.TabIndex  = 2;

            this.lblClock.Name      = "lblClock";
            this.lblClock.AutoSize  = false;
            this.lblClock.Size      = new System.Drawing.Size(170, 30);
            this.lblClock.Location  = new System.Drawing.Point(594, 14);
            this.lblClock.Font      = new System.Drawing.Font("Consolas", 13f, System.Drawing.FontStyle.Bold);
            this.lblClock.TextAlign = System.Drawing.ContentAlignment.MiddleRight;
            this.lblClock.TabIndex  = 2;

            this.panelHeader.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.lblTitle, this.lblDeviceBadge, this.lblAppVersion, this.lblClock });

            // ── Employee input ────────────────────────────────────────────────
            this.lblCodePrompt.Name     = "lblCodePrompt";
            this.lblCodePrompt.Text     = "Employee Code:";
            this.lblCodePrompt.AutoSize = true;
            this.lblCodePrompt.Location = new System.Drawing.Point(20, 76);
            this.lblCodePrompt.TabIndex = 1;

            this.txtEmployeeCode.Name            = "txtEmployeeCode";
            this.txtEmployeeCode.Location        = new System.Drawing.Point(20, 96);
            this.txtEmployeeCode.Size            = new System.Drawing.Size(260, 34);
            this.txtEmployeeCode.Font            = new System.Drawing.Font("Segoe UI", 13f);
            this.txtEmployeeCode.BorderStyle     = System.Windows.Forms.BorderStyle.FixedSingle;
            this.txtEmployeeCode.CharacterCasing = System.Windows.Forms.CharacterCasing.Upper;
            this.txtEmployeeCode.TabIndex        = 2;

            // Employee info — NOT cleared on punch, stays until new code typed
            this.lblEmpName.Name      = "lblEmpName";
            this.lblEmpName.Text      = "—";
            this.lblEmpName.AutoSize  = false;
            this.lblEmpName.Size      = new System.Drawing.Size(310, 30);
            this.lblEmpName.Location  = new System.Drawing.Point(20, 144);
            this.lblEmpName.Font      = new System.Drawing.Font("Segoe UI", 13f, System.Drawing.FontStyle.Bold);
            this.lblEmpName.TabIndex  = 3;

            this.lblEmpDept.Name      = "lblEmpDept";
            this.lblEmpDept.AutoSize  = true;
            this.lblEmpDept.Location  = new System.Drawing.Point(20, 178);
            this.lblEmpDept.TabIndex  = 4;

            this.lblPunchStatus.Name      = "lblPunchStatus";
            this.lblPunchStatus.AutoSize  = false;
            this.lblPunchStatus.Size      = new System.Drawing.Size(310, 24);
            this.lblPunchStatus.Location  = new System.Drawing.Point(20, 204);
            this.lblPunchStatus.TabIndex  = 5;

            // ── Buttons ───────────────────────────────────────────────────────
            this.btnCapture.Name      = "btnCapture";
            this.btnCapture.Text      = "Scan Fingerprint";
            this.btnCapture.Size      = new System.Drawing.Size(185, 44);
            this.btnCapture.Location  = new System.Drawing.Point(20, 244);
            this.btnCapture.Font      = new System.Drawing.Font("Segoe UI", 10.5f, System.Drawing.FontStyle.Bold);
            this.btnCapture.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnCapture.FlatAppearance.BorderSize = 0;
            this.btnCapture.TabIndex  = 6;

            this.btnSendOtp.Name      = "btnSendOtp";
            this.btnSendOtp.Text      = "Send OTP";
            this.btnSendOtp.Size      = new System.Drawing.Size(120, 44);
            this.btnSendOtp.Location  = new System.Drawing.Point(216, 244);
            this.btnSendOtp.Font      = new System.Drawing.Font("Segoe UI", 10f, System.Drawing.FontStyle.Bold);
            this.btnSendOtp.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnSendOtp.FlatAppearance.BorderSize = 0;
            this.btnSendOtp.Visible   = false;
            this.btnSendOtp.TabIndex  = 7;

            // ── Finger preview ────────────────────────────────────────────────
            // Box tightened from 240x240 to 200x200 — the FM220 SDK
            // renders the captured image at its native sensor size into
            // the centre of the control, so a larger box just leaves an
            // empty gray border. Smaller box = no empty margin.
            this.picPreview.Name        = "picPreview";
            this.picPreview.Location    = new System.Drawing.Point(380, 70);
            this.picPreview.Size        = new System.Drawing.Size(200, 200);
            this.picPreview.SizeMode    = System.Windows.Forms.PictureBoxSizeMode.Zoom;
            this.picPreview.BorderStyle = System.Windows.Forms.BorderStyle.FixedSingle;
            this.picPreview.TabIndex    = 8;

            // ── Result panel ──────────────────────────────────────────────────
            // Matched to picPreview height (200) so the two visually align.
            // Internal labels (icon/text/sub) all sit within 200px.
            this.panelResult.Name     = "panelResult";
            this.panelResult.Location = new System.Drawing.Point(618, 70);
            this.panelResult.Size     = new System.Drawing.Size(150, 200);
            this.panelResult.TabIndex = 9;

            this.lblResultIcon.Name      = "lblResultIcon";
            this.lblResultIcon.Text      = "?";
            this.lblResultIcon.Font      = new System.Drawing.Font("Segoe UI", 34f, System.Drawing.FontStyle.Bold);
            this.lblResultIcon.AutoSize  = false;
            this.lblResultIcon.Size      = new System.Drawing.Size(150, 82);
            this.lblResultIcon.Location  = new System.Drawing.Point(0, 16);
            this.lblResultIcon.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblResultIcon.TabIndex  = 0;

            this.lblResultText.Name      = "lblResultText";
            this.lblResultText.Text      = "AWAITING";
            this.lblResultText.Font      = new System.Drawing.Font("Segoe UI", 9f, System.Drawing.FontStyle.Bold);
            this.lblResultText.AutoSize  = false;
            this.lblResultText.Size      = new System.Drawing.Size(150, 24);
            this.lblResultText.Location  = new System.Drawing.Point(0, 102);
            this.lblResultText.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblResultText.TabIndex  = 1;

            this.lblResultSub.Name      = "lblResultSub";
            this.lblResultSub.AutoSize  = false;
            this.lblResultSub.Size      = new System.Drawing.Size(142, 46);
            this.lblResultSub.Location  = new System.Drawing.Point(4, 130);
            this.lblResultSub.TextAlign = System.Drawing.ContentAlignment.TopCenter;
            this.lblResultSub.TabIndex  = 2;

            this.panelResult.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.lblResultIcon, this.lblResultText, this.lblResultSub });

            // ── Previous punch label ──────────────────────────────────────────
            // Sits just below the Scan / OTP buttons (which end at y=288).
            // Earlier y=280 collided with the buttons — fixed.
            this.lblPrevPunch.Name      = "lblPrevPunch";
            this.lblPrevPunch.AutoSize  = false;
            this.lblPrevPunch.Size      = new System.Drawing.Size(740, 22);
            this.lblPrevPunch.Location  = new System.Drawing.Point(20, 296);
            this.lblPrevPunch.Font      = new System.Drawing.Font("Segoe UI", 9f, System.Drawing.FontStyle.Italic);
            this.lblPrevPunch.TabIndex  = 10;

            // ── Score + NFIQ text (replaces progress bar) ─────────────────────
            this.lblScoreInfo.Name      = "lblScoreInfo";
            this.lblScoreInfo.AutoSize  = false;
            this.lblScoreInfo.Size      = new System.Drawing.Size(740, 28);
            this.lblScoreInfo.Location  = new System.Drawing.Point(20, 324);
            this.lblScoreInfo.Font      = new System.Drawing.Font("Segoe UI", 10f, System.Drawing.FontStyle.Bold);
            this.lblScoreInfo.TabIndex  = 11;

            // ── panelBottom ───────────────────────────────────────────────────
            this.panelBottom.Name     = "panelBottom";
            this.panelBottom.Location = new System.Drawing.Point(0, 482);
            this.panelBottom.Size     = new System.Drawing.Size(780, 58);
            this.panelBottom.TabIndex = 12;

            this.lblDeviceStatus.Name     = "lblDeviceStatus";
            this.lblDeviceStatus.Text     = "Initializing…";
            this.lblDeviceStatus.AutoSize = true;
            this.lblDeviceStatus.Location = new System.Drawing.Point(16, 8);
            this.lblDeviceStatus.TabIndex = 0;

            this.lblDeviceSerial.Name     = "lblDeviceSerial";
            this.lblDeviceSerial.AutoSize = true;
            this.lblDeviceSerial.Location = new System.Drawing.Point(16, 28);
            this.lblDeviceSerial.TabIndex = 1;

            this.lblStatus.Name      = "lblStatus";
            this.lblStatus.AutoSize  = false;
            this.lblStatus.Size      = new System.Drawing.Size(380, 50);
            this.lblStatus.Location  = new System.Drawing.Point(200, 4);
            this.lblStatus.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblStatus.Font      = new System.Drawing.Font("Segoe UI", 9.5f);
            this.lblStatus.TabIndex  = 2;

            this.btnRefresh.Name      = "btnRefresh";
            this.btnRefresh.Text      = "⟳";
            this.btnRefresh.Size      = new System.Drawing.Size(40, 36);
            this.btnRefresh.Location  = new System.Drawing.Point(682, 11);
            this.btnRefresh.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnRefresh.Font      = new System.Drawing.Font("Segoe UI", 13f);
            this.btnRefresh.TabIndex  = 3;

            this.btnAdmin.Name      = "btnAdmin";
            this.btnAdmin.Text      = "⚙";
            this.btnAdmin.Size      = new System.Drawing.Size(40, 36);
            this.btnAdmin.Location  = new System.Drawing.Point(730, 11);
            this.btnAdmin.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnAdmin.Font      = new System.Drawing.Font("Segoe UI", 13f);
            this.btnAdmin.TabIndex  = 4;

            this.panelBottom.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.lblDeviceStatus, this.lblDeviceSerial, this.lblStatus,
                this.btnRefresh, this.btnAdmin });

            // ── Add all to form ───────────────────────────────────────────────
            this.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.panelHeader,
                this.lblCodePrompt, this.txtEmployeeCode,
                this.lblEmpName, this.lblEmpDept, this.lblPunchStatus,
                this.btnCapture, this.btnSendOtp,
                this.picPreview, this.panelResult,
                this.lblPrevPunch,
                this.lblScoreInfo,
                this.panelBottom,
            });

            this.panelHeader.ResumeLayout(false);
            this.panelHeader.PerformLayout();
            this.panelResult.ResumeLayout(false);
            this.panelBottom.ResumeLayout(false);
            this.panelBottom.PerformLayout();
            ((System.ComponentModel.ISupportInitialize)(this.picPreview)).EndInit();
            this.ResumeLayout(false);
            this.PerformLayout();
        }

        #endregion

        private System.Windows.Forms.Panel       panelHeader;
        private System.Windows.Forms.Label       lblTitle;
        private System.Windows.Forms.Label       lblDeviceBadge;
        private System.Windows.Forms.Label       lblAppVersion;
        private System.Windows.Forms.Label       lblClock;
        private System.Windows.Forms.Label       lblCodePrompt;
        private System.Windows.Forms.TextBox     txtEmployeeCode;
        private System.Windows.Forms.Label       lblEmpName;
        private System.Windows.Forms.Label       lblEmpDept;
        private System.Windows.Forms.Label       lblPunchStatus;
        private System.Windows.Forms.Button      btnCapture;
        private System.Windows.Forms.Button      btnSendOtp;
        private System.Windows.Forms.PictureBox  picPreview;
        private System.Windows.Forms.Panel       panelResult;
        private System.Windows.Forms.Label       lblResultIcon;
        private System.Windows.Forms.Label       lblResultText;
        private System.Windows.Forms.Label       lblResultSub;
        private System.Windows.Forms.Label       lblPrevPunch;
        private System.Windows.Forms.Label       lblScoreInfo;
        private System.Windows.Forms.Panel       panelBottom;
        private System.Windows.Forms.Label       lblDeviceStatus;
        private System.Windows.Forms.Label       lblDeviceSerial;
        private System.Windows.Forms.Label       lblStatus;
        private System.Windows.Forms.Button      btnRefresh;
        private System.Windows.Forms.Button      btnAdmin;
    }
}
