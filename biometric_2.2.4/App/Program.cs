using System;
using System.Windows.Forms;
using BiometricAttendance.App.Forms;

namespace BiometricAttendance.App
{
    static class Program
    {
        [STAThread]
        static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            try
            {
                // Validate config before launching
                _ = AppConfig.DeviceType;
                _ = AppConfig.ApiBaseUrl;
                _ = AppConfig.ApiKey;
                _ = AppConfig.LocationId;
            }
            catch (Exception ex)
            {
                MessageBox.Show(
                    "Configuration error:\n\n" + ex.Message +
                    "\n\nPlease check App.config and restart.",
                    "Startup Error",
                    MessageBoxButtons.OK,
                    MessageBoxIcon.Error);
                return;
            }

            Application.Run(new MainForm());
        }
    }
}
