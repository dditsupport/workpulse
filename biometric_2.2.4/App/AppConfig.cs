using System;
using System.Configuration;
using BiometricAttendance.Core.Interfaces;

namespace BiometricAttendance.App
{
    /// <summary>
    /// Reads all configuration from App.config.
    /// Single source of truth for deploy-time settings.
    /// </summary>
    public static class AppConfig
    {
        private static string Get(string key, string defaultValue = "")
            => ConfigurationManager.AppSettings[key] ?? defaultValue;

        // ── Device ────────────────────────────────────────────────────────────
        public static DeviceType DeviceType
        {
            get
            {
                var val = Get("DeviceType", "MFS500").ToUpperInvariant();
                return val == "FM220" ? DeviceType.FM220 : DeviceType.MFS500;
            }
        }

        // ── API ───────────────────────────────────────────────────────────────
        public static string ApiBaseUrl
        {
            get
            {
                var url = Get("ApiBaseUrl");
                if (string.IsNullOrWhiteSpace(url))
                    throw new Exception("ApiBaseUrl not set in App.config.");
                return url;
            }
        }

        // Hardcoded — previously read from App.config.
        // Must match the X-API-KEY value configured on the server.
        private const string HardcodedApiKey =
            "yourapi";

        public static string ApiKey => HardcodedApiKey;

        // ── Location ──────────────────────────────────────────────────────────
        public static int LocationId
        {
            get
            {
                if (int.TryParse(Get("LocationId"), out int id) && id > 0)
                    return id;
                throw new Exception("LocationId not set or invalid in App.config.");
            }
        }

        // ── Fallback punch interval (overridden by server settings) ───────────
        public static int MinPunchIntervalMinutes
        {
            get
            {
                if (int.TryParse(Get("MinPunchIntervalMinutes"), out int val) && val > 0)
                    return val;
                throw new Exception("MinPunchIntervalMinutes not set or invalid in App.config.");
            }
        }
    }
}
