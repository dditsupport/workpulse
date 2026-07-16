using System;
using System.Drawing;
using System.Windows.Forms;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Devices;

namespace BiometricAttendance.App.Helpers
{
    // =========================================================================
    // DEVICE FACTORY
    // Creates the correct IFingerprintDevice from App.config DeviceType.
    // =========================================================================
    public static class DeviceFactory
    {
        public static IFingerprintDevice Create(DeviceType deviceType)
        {
            switch (deviceType)
            {
                case DeviceType.MFS500: return new Mfs500Device();
                case DeviceType.FM220:  return new Fm220Device();
                default:
                    throw new NotSupportedException(
                        $"Device type '{deviceType}' is not supported.");
            }
        }
    }

    // =========================================================================
    // UI THEME
    // Accent colors and labels per device type.
    // MFS500 → Blue family
    // FM220  → Green family
    // =========================================================================
    public static class UiTheme
    {
        // ── Accent (primary action, highlights) ───────────────────────────────
        public static Color Accent(DeviceType dt) =>
            dt == DeviceType.MFS500
                ? Color.FromArgb(26,  143, 227)   // Blue
                : Color.FromArgb(39,  174,  96);  // Green

        // ── Accent dark (hover, pressed) ──────────────────────────────────────
        public static Color AccentDark(DeviceType dt) =>
            dt == DeviceType.MFS500
                ? Color.FromArgb(18,  105, 179)
                : Color.FromArgb(30,  132,  73);

        // ── Success ───────────────────────────────────────────────────────────
        public static readonly Color Success = Color.FromArgb(39, 174, 96);
        public static readonly Color Error   = Color.FromArgb(220, 80, 80);
        public static readonly Color Warning = Color.FromArgb(230, 180, 60);
        public static readonly Color Info    = Color.FromArgb(150, 150, 170);

        // ── Background ────────────────────────────────────────────────────────
        public static readonly Color Background    = Color.FromArgb(28,  28,  36);
        public static readonly Color Surface       = Color.FromArgb(38,  38,  50);
        public static readonly Color SurfaceLight  = Color.FromArgb(50,  50,  65);
        public static readonly Color TextPrimary   = Color.FromArgb(230, 230, 240);
        public static readonly Color TextSecondary = Color.FromArgb(140, 140, 160);

        // ── Device label ──────────────────────────────────────────────────────
        public static string DeviceLabel(DeviceType dt) =>
            dt == DeviceType.MFS500 ? "MFS500" : "FM220";

        // ── Title bar suffix ──────────────────────────────────────────────────
        public static string AppTitle(DeviceType dt) =>
            $"Biometric Attendance  ·  {DeviceLabel(dt)}";

        // ── Apply accent color to a button ────────────────────────────────────
        public static void ApplyAccent(Button btn, DeviceType dt)
        {
            btn.BackColor = Accent(dt);
            btn.ForeColor = Color.White;
            btn.FlatStyle = FlatStyle.Flat;
            btn.FlatAppearance.BorderSize = 0;
        }

        // ── NFIQ quality color ────────────────────────────────────────────────
        public static Color NfiqColor(int nfiq)
        {
            switch (nfiq)
            {
                case 1:  return Color.FromArgb(39,  174,  96);
                case 2:  return Color.FromArgb(80,  160,  80);
                case 3:  return Color.FromArgb(200, 160,  40);
                case 4:  return Color.FromArgb(200, 100,  30);
                default: return Color.FromArgb(200,  60,  60);
            }
        }

        // ── Score bar color (based on normalized score 0–100) ─────────────────
        public static Color ScoreColor(int normalizedScore)
        {
            if (normalizedScore >= 70) return Color.FromArgb(39, 174, 96);
            if (normalizedScore >= 50) return Color.FromArgb(200, 160, 40);
            return Color.FromArgb(200, 60, 60);
        }
    }

    // =========================================================================
    // UI RESET TIMER
    // Resets UI to idle state after a configurable delay (default 5s).
    // =========================================================================
    public class UiResetTimer : IDisposable
    {
        private readonly Timer  _timer;
        private readonly Action _resetAction;
        private readonly int    _delayMs;

        public UiResetTimer(Action resetAction, int delayMs = 5000)
        {
            _resetAction = resetAction ?? throw new ArgumentNullException(nameof(resetAction));
            _delayMs     = delayMs;
            _timer       = new Timer { Interval = delayMs };
            _timer.Tick += (s, e) =>
            {
                _timer.Stop();
                _resetAction();
            };
        }

        /// <summary>Restart the countdown.</summary>
        public void Reset()
        {
            _timer.Stop();
            _timer.Start();
        }

        /// <summary>Cancel pending reset.</summary>
        public void Cancel() => _timer.Stop();

        public void Dispose()
        {
            _timer.Stop();
            _timer.Dispose();
        }
    }
}
