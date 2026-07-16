namespace BiometricAttendance.App.Forms
{
    partial class EnrollmentForm
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
            this.lblTitle        = new System.Windows.Forms.Label();
            this.lblDeviceBadge  = new System.Windows.Forms.Label();
            this.lblCodePrompt   = new System.Windows.Forms.Label();
            this.txtEmployeeCode = new System.Windows.Forms.TextBox();
            this.btnLookup       = new System.Windows.Forms.Button();
            this.panelEmp        = new System.Windows.Forms.Panel();
            this.lblEmpCodeKey   = new System.Windows.Forms.Label();
            this.lblEmpNameKey   = new System.Windows.Forms.Label();
            this.lblEmpDeptKey   = new System.Windows.Forms.Label();
            this.lblEmpPhoneKey  = new System.Windows.Forms.Label();
            this.lblEmpCode      = new System.Windows.Forms.Label();
            this.lblEmpName      = new System.Windows.Forms.Label();
            this.lblEmpDept      = new System.Windows.Forms.Label();
            this.lblEmpPhone     = new System.Windows.Forms.Label();
            this.picPreview      = new System.Windows.Forms.PictureBox();
            this.lblNfiqBadge    = new System.Windows.Forms.Label();
            this.dot1            = new System.Windows.Forms.Panel();
            this.dot2            = new System.Windows.Forms.Panel();
            this.dot3            = new System.Windows.Forms.Panel();
            this.lblProgress     = new System.Windows.Forms.Label();
            this.btnCapture      = new System.Windows.Forms.Button();
            this.btnReset        = new System.Windows.Forms.Button();
            this.progressDup     = new System.Windows.Forms.ProgressBar();
            this.lblDupProgress  = new System.Windows.Forms.Label();
            this.lblInstruction  = new System.Windows.Forms.Label();
            this.lblStatus       = new System.Windows.Forms.Label();
            this.panelEmp.SuspendLayout();
            ((System.ComponentModel.ISupportInitialize)(this.picPreview)).BeginInit();
            this.SuspendLayout();

            // ── Form ──────────────────────────────────────────────────────────
            this.ClientSize      = new System.Drawing.Size(720, 540);
            this.FormBorderStyle = System.Windows.Forms.FormBorderStyle.FixedSingle;
            this.MaximizeBox     = false;
            this.StartPosition   = System.Windows.Forms.FormStartPosition.CenterParent;
            this.Font            = new System.Drawing.Font("Segoe UI", 9.5f);
            this.Name            = "EnrollmentForm";
            this.Text            = "Fingerprint Enrollment";
            this.Icon            = System.Drawing.Icon.ExtractAssociatedIcon(System.Reflection.Assembly.GetExecutingAssembly().Location);

            // ── lblTitle ──────────────────────────────────────────────────────
            this.lblTitle.Name      = "lblTitle";
            this.lblTitle.Text      = "FINGERPRINT ENROLLMENT";
            this.lblTitle.Font      = new System.Drawing.Font("Segoe UI", 15f, System.Drawing.FontStyle.Bold);
            this.lblTitle.AutoSize  = false;
            this.lblTitle.Size      = new System.Drawing.Size(510, 38);
            this.lblTitle.Location  = new System.Drawing.Point(20, 18);
            this.lblTitle.TabIndex  = 0;

            // ── lblDeviceBadge ────────────────────────────────────────────────
            this.lblDeviceBadge.Name      = "lblDeviceBadge";
            this.lblDeviceBadge.AutoSize  = false;
            this.lblDeviceBadge.Size      = new System.Drawing.Size(82, 28);
            this.lblDeviceBadge.Location  = new System.Drawing.Point(618, 24);
            this.lblDeviceBadge.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblDeviceBadge.Font      = new System.Drawing.Font("Segoe UI", 9f, System.Drawing.FontStyle.Bold);
            this.lblDeviceBadge.TabIndex  = 1;

            // ── Code lookup ───────────────────────────────────────────────────
            this.lblCodePrompt.Name     = "lblCodePrompt";
            this.lblCodePrompt.Text     = "Employee Code:";
            this.lblCodePrompt.AutoSize = true;
            this.lblCodePrompt.Location = new System.Drawing.Point(20, 76);
            this.lblCodePrompt.TabIndex = 2;

            this.txtEmployeeCode.Name            = "txtEmployeeCode";
            this.txtEmployeeCode.Location        = new System.Drawing.Point(20, 96);
            this.txtEmployeeCode.Size            = new System.Drawing.Size(220, 30);
            this.txtEmployeeCode.BorderStyle     = System.Windows.Forms.BorderStyle.FixedSingle;
            this.txtEmployeeCode.Font            = new System.Drawing.Font("Segoe UI", 11f);
            this.txtEmployeeCode.CharacterCasing = System.Windows.Forms.CharacterCasing.Upper;
            this.txtEmployeeCode.TabIndex        = 3;

            this.btnLookup.Name      = "btnLookup";
            this.btnLookup.Text      = "Lookup";
            this.btnLookup.Size      = new System.Drawing.Size(100, 30);
            this.btnLookup.Location  = new System.Drawing.Point(250, 96);
            this.btnLookup.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnLookup.FlatAppearance.BorderSize = 0;
            this.btnLookup.TabIndex  = 4;

            // ── Employee info panel ───────────────────────────────────────────
            this.panelEmp.Name     = "panelEmp";
            this.panelEmp.Location = new System.Drawing.Point(20, 144);
            this.panelEmp.Size     = new System.Drawing.Size(350, 115);
            this.panelEmp.TabIndex = 5;

            this.lblEmpCodeKey.Name     = "lblEmpCodeKey";  this.lblEmpCodeKey.Text  = "Code:";
            this.lblEmpCodeKey.AutoSize = true; this.lblEmpCodeKey.Location = new System.Drawing.Point(10, 10); this.lblEmpCodeKey.TabIndex = 0;
            this.lblEmpNameKey.Name     = "lblEmpNameKey";  this.lblEmpNameKey.Text  = "Name:";
            this.lblEmpNameKey.AutoSize = true; this.lblEmpNameKey.Location = new System.Drawing.Point(10, 36); this.lblEmpNameKey.TabIndex = 1;
            this.lblEmpDeptKey.Name     = "lblEmpDeptKey";  this.lblEmpDeptKey.Text  = "Dept:";
            this.lblEmpDeptKey.AutoSize = true; this.lblEmpDeptKey.Location = new System.Drawing.Point(10, 62); this.lblEmpDeptKey.TabIndex = 2;
            this.lblEmpPhoneKey.Name    = "lblEmpPhoneKey"; this.lblEmpPhoneKey.Text = "Phone:";
            this.lblEmpPhoneKey.AutoSize= true; this.lblEmpPhoneKey.Location= new System.Drawing.Point(10, 88); this.lblEmpPhoneKey.TabIndex = 3;

            this.lblEmpCode.Name  = "lblEmpCode";  this.lblEmpCode.Text  = "—"; this.lblEmpCode.AutoSize  = false; this.lblEmpCode.Size  = new System.Drawing.Size(250, 20); this.lblEmpCode.Location  = new System.Drawing.Point(70, 10);  this.lblEmpCode.TabIndex  = 4;
            this.lblEmpName.Name  = "lblEmpName";  this.lblEmpName.Text  = "—"; this.lblEmpName.AutoSize  = false; this.lblEmpName.Size  = new System.Drawing.Size(250, 20); this.lblEmpName.Location  = new System.Drawing.Point(70, 36);  this.lblEmpName.TabIndex  = 5;
            this.lblEmpDept.Name  = "lblEmpDept";  this.lblEmpDept.Text  = "—"; this.lblEmpDept.AutoSize  = false; this.lblEmpDept.Size  = new System.Drawing.Size(250, 20); this.lblEmpDept.Location  = new System.Drawing.Point(70, 62);  this.lblEmpDept.TabIndex  = 6;
            this.lblEmpPhone.Name = "lblEmpPhone"; this.lblEmpPhone.Text = "—"; this.lblEmpPhone.AutoSize = false; this.lblEmpPhone.Size = new System.Drawing.Size(250, 20); this.lblEmpPhone.Location = new System.Drawing.Point(70, 88); this.lblEmpPhone.TabIndex = 7;

            this.panelEmp.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.lblEmpCodeKey, this.lblEmpCode,
                this.lblEmpNameKey, this.lblEmpName,
                this.lblEmpDeptKey, this.lblEmpDept,
                this.lblEmpPhoneKey,this.lblEmpPhone,
            });

            // ── Finger preview ────────────────────────────────────────────────
            // Box tightened from 300x270 to 240x220 — the FM220 SDK
            // renders the captured image at its native sensor size into
            // the centre of the control, so a larger box just leaves an
            // empty gray border. Smaller box = no empty margin.
            this.picPreview.Name        = "picPreview";
            this.picPreview.Location    = new System.Drawing.Point(430, 68);
            this.picPreview.Size        = new System.Drawing.Size(240, 220);
            this.picPreview.SizeMode    = System.Windows.Forms.PictureBoxSizeMode.Zoom;
            this.picPreview.BorderStyle = System.Windows.Forms.BorderStyle.FixedSingle;
            this.picPreview.TabIndex    = 6;

            // Image-quality badge under preview — matches MainForm wording
            // ("Image quality: <band>") so users see the same label in
            // both flows. Width tracks the new picPreview width.
            this.lblNfiqBadge.Name      = "lblNfiqBadge";
            this.lblNfiqBadge.AutoSize  = false;
            this.lblNfiqBadge.Size      = new System.Drawing.Size(240, 22);
            this.lblNfiqBadge.Location  = new System.Drawing.Point(430, 292);
            this.lblNfiqBadge.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblNfiqBadge.Font      = new System.Drawing.Font("Segoe UI", 9f, System.Drawing.FontStyle.Bold);
            this.lblNfiqBadge.TabIndex  = 7;

            // ── Capture progress dots ─────────────────────────────────────────
            this.dot1.Name = "dot1"; this.dot1.Size = new System.Drawing.Size(26, 26); this.dot1.Location = new System.Drawing.Point(20, 278); this.dot1.TabIndex = 8;
            this.dot2.Name = "dot2"; this.dot2.Size = new System.Drawing.Size(26, 26); this.dot2.Location = new System.Drawing.Point(56, 278); this.dot2.TabIndex = 9;
            this.dot3.Name = "dot3"; this.dot3.Size = new System.Drawing.Size(26, 26); this.dot3.Location = new System.Drawing.Point(92, 278); this.dot3.TabIndex = 10;

            this.lblProgress.Name     = "lblProgress";
            this.lblProgress.AutoSize = true;
            this.lblProgress.Location = new System.Drawing.Point(132, 282);
            this.lblProgress.Text     = "0 / 3  quality captures";
            this.lblProgress.TabIndex = 11;

            // ── Capture / Reset buttons ───────────────────────────────────────
            this.btnCapture.Name      = "btnCapture";
            this.btnCapture.Text      = "▶  Capture Finger";
            this.btnCapture.Size      = new System.Drawing.Size(175, 44);
            this.btnCapture.Location  = new System.Drawing.Point(20, 322);
            this.btnCapture.Font      = new System.Drawing.Font("Segoe UI", 10f, System.Drawing.FontStyle.Bold);
            this.btnCapture.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnCapture.FlatAppearance.BorderSize = 0;
            this.btnCapture.TabIndex  = 12;

            this.btnReset.Name      = "btnReset";
            this.btnReset.Text      = "Reset";
            this.btnReset.Size      = new System.Drawing.Size(90, 44);
            this.btnReset.Location  = new System.Drawing.Point(206, 322);
            this.btnReset.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnReset.TabIndex  = 13;

            // ── Instruction label (contextual guidance) ───────────────────────
            this.lblInstruction.Name      = "lblInstruction";
            this.lblInstruction.AutoSize  = false;
            this.lblInstruction.Size      = new System.Drawing.Size(370, 44);
            this.lblInstruction.Location  = new System.Drawing.Point(20, 376);
            this.lblInstruction.Font      = new System.Drawing.Font("Segoe UI", 9.5f, System.Drawing.FontStyle.Italic);
            this.lblInstruction.TabIndex  = 14;

            // ── Duplicate check progress (hidden by default) ──────────────────
            this.progressDup.Name     = "progressDup";
            this.progressDup.Location = new System.Drawing.Point(20, 428);
            this.progressDup.Size     = new System.Drawing.Size(370, 14);
            this.progressDup.Minimum  = 0;
            this.progressDup.Maximum  = 100;
            this.progressDup.Visible  = false;
            this.progressDup.TabIndex = 15;

            this.lblDupProgress.Name      = "lblDupProgress";
            this.lblDupProgress.AutoSize  = false;
            this.lblDupProgress.Size      = new System.Drawing.Size(370, 20);
            this.lblDupProgress.Location  = new System.Drawing.Point(20, 446);
            this.lblDupProgress.Font      = new System.Drawing.Font("Segoe UI", 8.5f);
            this.lblDupProgress.Visible   = false;
            this.lblDupProgress.TabIndex  = 16;

            // ── Status bar ────────────────────────────────────────────────────
            this.lblStatus.Name      = "lblStatus";
            this.lblStatus.AutoSize  = false;
            this.lblStatus.Size      = new System.Drawing.Size(680, 26);
            this.lblStatus.Location  = new System.Drawing.Point(20, 500);
            this.lblStatus.Font      = new System.Drawing.Font("Segoe UI", 9.5f);
            this.lblStatus.TabIndex  = 17;

            // ── Add all controls ──────────────────────────────────────────────
            this.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.lblTitle, this.lblDeviceBadge,
                this.lblCodePrompt, this.txtEmployeeCode, this.btnLookup,
                this.panelEmp,
                this.picPreview, this.lblNfiqBadge,
                this.dot1, this.dot2, this.dot3, this.lblProgress,
                this.btnCapture, this.btnReset,
                this.lblInstruction,
                this.progressDup, this.lblDupProgress,
                this.lblStatus,
            });

            this.panelEmp.ResumeLayout(false);
            this.panelEmp.PerformLayout();
            ((System.ComponentModel.ISupportInitialize)(this.picPreview)).EndInit();
            this.ResumeLayout(false);
            this.PerformLayout();
        }

        #endregion

        private System.Windows.Forms.Label      lblTitle;
        private System.Windows.Forms.Label      lblDeviceBadge;
        private System.Windows.Forms.Label      lblCodePrompt;
        private System.Windows.Forms.TextBox    txtEmployeeCode;
        private System.Windows.Forms.Button     btnLookup;
        private System.Windows.Forms.Panel      panelEmp;
        private System.Windows.Forms.Label      lblEmpCodeKey, lblEmpNameKey, lblEmpDeptKey, lblEmpPhoneKey;
        private System.Windows.Forms.Label      lblEmpCode, lblEmpName, lblEmpDept, lblEmpPhone;
        private System.Windows.Forms.PictureBox picPreview;
        private System.Windows.Forms.Label      lblNfiqBadge;
        private System.Windows.Forms.Panel      dot1, dot2, dot3;
        private System.Windows.Forms.Label      lblProgress;
        private System.Windows.Forms.Button     btnCapture;
        private System.Windows.Forms.Button     btnReset;
        private System.Windows.Forms.Label      lblInstruction;
        private System.Windows.Forms.ProgressBar progressDup;
        private System.Windows.Forms.Label      lblDupProgress;
        private System.Windows.Forms.Label      lblStatus;
    }
}
