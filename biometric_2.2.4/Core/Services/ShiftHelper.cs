using System;
using System.Threading;

namespace BiometricAttendance.Core.Services
{
    /// <summary>
    /// Shift-day arithmetic. Must match the server
    /// (api/attendance.php: DATE(punch_time - INTERVAL N HOUR)).
    /// Punches before the cutoff belong to the previous calendar date's shift.
    ///
    /// The cutoff value is fetched from the server at startup via
    /// api/system_settings.php (key: "ShiftCutoffHour"). If the fetch fails
    /// or the key is missing, this default (6) is used — one-hour buffer
    /// before the 7AM shift start.
    /// </summary>
    public static class ShiftHelper
    {
        private static int _shiftCutoffHours = 6;

        public static int ShiftCutoffHours => Volatile.Read(ref _shiftCutoffHours);

        /// <summary>
        /// Apply a server-provided cutoff. Ignores unparseable or out-of-range
        /// (0–23) values; callers can rely on the default if the server is
        /// unreachable.
        /// </summary>
        public static void SetCutoffHour(string raw)
        {
            if (int.TryParse(raw, out int h) && h >= 0 && h <= 23)
                Volatile.Write(ref _shiftCutoffHours, h);
        }

        public static DateTime ShiftDay(DateTime dt) =>
            dt.AddHours(-ShiftCutoffHours).Date;
    }
}
