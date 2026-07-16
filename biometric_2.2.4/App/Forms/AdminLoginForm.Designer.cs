namespace BiometricAttendance.App.Forms
{
    partial class AdminLoginForm
    {
        private System.ComponentModel.IContainer components = null;

        protected override void Dispose(bool disposing)
        {
            if (disposing && (components != null))
                components.Dispose();
            base.Dispose(disposing);
        }

        #region Windows Form Designer generated code

        private void InitializeComponent()
        {
            this.lblTitle    = new System.Windows.Forms.Label();
            this.lblPrompt   = new System.Windows.Forms.Label();
            this.txtPassword = new System.Windows.Forms.TextBox();
            this.btnLogin    = new System.Windows.Forms.Button();
            this.btnCancel   = new System.Windows.Forms.Button();
            this.lblError    = new System.Windows.Forms.Label();
            this.SuspendLayout();

            // ── Form ──────────────────────────────────────────────────────────
            this.ClientSize        = new System.Drawing.Size(360, 280);
            this.FormBorderStyle   = System.Windows.Forms.FormBorderStyle.FixedDialog;
            this.MaximizeBox       = false;
            this.MinimizeBox       = false;
            this.StartPosition     = System.Windows.Forms.FormStartPosition.CenterParent;
            this.Font              = new System.Drawing.Font("Segoe UI", 9.5f);
            this.Name              = "AdminLoginForm";
            this.Text              = "Admin Login";
            this.Icon              = System.Drawing.Icon.ExtractAssociatedIcon(System.Reflection.Assembly.GetExecutingAssembly().Location);

            // ── lblTitle ──────────────────────────────────────────────────────
            this.lblTitle.Name      = "lblTitle";
            this.lblTitle.Text      = "ADMIN LOGIN";
            this.lblTitle.Font      = new System.Drawing.Font("Segoe UI", 16f, System.Drawing.FontStyle.Bold);
            this.lblTitle.AutoSize  = false;
            this.lblTitle.Size      = new System.Drawing.Size(320, 40);
            this.lblTitle.Location  = new System.Drawing.Point(20, 30);
            this.lblTitle.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblTitle.TabIndex  = 0;

            // ── lblPrompt ─────────────────────────────────────────────────────
            this.lblPrompt.Name      = "lblPrompt";
            this.lblPrompt.Text      = "Enter admin password:";
            this.lblPrompt.AutoSize  = true;
            this.lblPrompt.Location  = new System.Drawing.Point(20, 95);
            this.lblPrompt.TabIndex  = 1;

            // ── txtPassword ───────────────────────────────────────────────────
            this.txtPassword.Name         = "txtPassword";
            this.txtPassword.Location     = new System.Drawing.Point(20, 120);
            this.txtPassword.Size         = new System.Drawing.Size(320, 30);
            this.txtPassword.PasswordChar = '●';
            this.txtPassword.BorderStyle  = System.Windows.Forms.BorderStyle.FixedSingle;
            this.txtPassword.Font         = new System.Drawing.Font("Segoe UI", 11f);
            this.txtPassword.TabIndex     = 2;
            this.txtPassword.KeyDown     += new System.Windows.Forms.KeyEventHandler(this.txtPassword_KeyDown);

            // ── btnLogin ──────────────────────────────────────────────────────
            this.btnLogin.Name      = "btnLogin";
            this.btnLogin.Text      = "Login";
            this.btnLogin.Size      = new System.Drawing.Size(150, 38);
            this.btnLogin.Location  = new System.Drawing.Point(20, 175);
            this.btnLogin.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnLogin.FlatAppearance.BorderSize = 0;
            this.btnLogin.TabIndex  = 3;
            this.btnLogin.Click    += new System.EventHandler(this.btnLogin_Click);

            // ── btnCancel ─────────────────────────────────────────────────────
            this.btnCancel.Name      = "btnCancel";
            this.btnCancel.Text      = "Cancel";
            this.btnCancel.Size      = new System.Drawing.Size(150, 38);
            this.btnCancel.Location  = new System.Drawing.Point(190, 175);
            this.btnCancel.FlatStyle = System.Windows.Forms.FlatStyle.Flat;
            this.btnCancel.TabIndex  = 4;
            this.btnCancel.Click    += new System.EventHandler(this.btnCancel_Click);

            // ── lblError ──────────────────────────────────────────────────────
            this.lblError.Name      = "lblError";
            this.lblError.AutoSize  = false;
            this.lblError.Size      = new System.Drawing.Size(320, 24);
            this.lblError.Location  = new System.Drawing.Point(20, 225);
            this.lblError.TextAlign = System.Drawing.ContentAlignment.MiddleCenter;
            this.lblError.TabIndex  = 5;

            // ── Add controls ──────────────────────────────────────────────────
            this.Controls.AddRange(new System.Windows.Forms.Control[] {
                this.lblTitle,
                this.lblPrompt,
                this.txtPassword,
                this.btnLogin,
                this.btnCancel,
                this.lblError,
            });

            this.ResumeLayout(false);
            this.PerformLayout();
        }

        #endregion

        private System.Windows.Forms.Label   lblTitle;
        private System.Windows.Forms.Label   lblPrompt;
        private System.Windows.Forms.TextBox txtPassword;
        private System.Windows.Forms.Button  btnLogin;
        private System.Windows.Forms.Button  btnCancel;
        private System.Windows.Forms.Label   lblError;
    }
}
