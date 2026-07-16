using BiometricAttendance.Core.Interfaces;

namespace BiometricAttendance.Core.Services
{
    /// <summary>
    /// Normalizes raw device scores to 0–100 for storage and comparison.
    ///   MFS500: raw 0–1000 → normalized = raw / 10
    ///   FM220:  raw 0–100  → normalized = raw (no change)
    /// All DB thresholds and logged scores use normalized values.
    /// </summary>
    public static class ScoreHelper
    {
        public static int Normalize(int rawScore, DeviceType deviceType)
        {
            int normalized;
            switch (deviceType)
            {
                case DeviceType.MFS500:
                    normalized = rawScore / 10;
                    break;
                case DeviceType.FM220:
                    // FIX: FM220 SDK can return scores above 100 for excellent
                    // matches. Previously passed through unchanged, which caused
                    // "Score: 100000 / 100" in the UI. The clamp below handles it.
                    normalized = rawScore;
                    break;
                default:
                    normalized = rawScore;
                    break;
            }

            // Defensive clamp: normalized scores must always be in [0, 100].
            // Prevents display oddities and keeps threshold comparisons on the
            // same scale as the stored DB thresholds.
            if (normalized < 0) normalized = 0;
            if (normalized > 100) normalized = 100;
            return normalized;
        }

        /// <summary>
        /// Reads the first-finger-view minutiae count from an ISO/IEC 19794-2
        /// (FMR) template — the format FM220 produces. Returns false if the
        /// bytes are too short or lack the "FMR\0" magic; callers must then
        /// NOT gate on minutiae (let NFIQ + the matcher decide).
        /// Header: "FMR\0"(0-3) " 20\0"(4-7) length(8-11) ... numViews(22)
        /// reserved(23) fingerPos(24) view/impression(25) quality(26)
        /// minutiaeCount(27).
        /// </summary>
        public static bool TryGetIsoMinutiaeCount(byte[] template, out int count)
        {
            count = 0;
            if (template == null || template.Length < 28) return false;
            if (template[0] != (byte)'F' || template[1] != (byte)'M' ||
                template[2] != (byte)'R' || template[3] != 0x00) return false;
            count = template[27];
            return true;
        }

        /// <summary>
        /// Human-readable quality label for a normalized score.
        /// </summary>
        public static string ScoreLabel(int normalizedScore)
        {
            if (normalizedScore >= 80) return "Excellent";
            if (normalizedScore >= 65) return "Good";
            if (normalizedScore >= 50) return "Acceptable";
            if (normalizedScore >= 35) return "Weak";
            return "Poor";
        }

        /// <summary>
        /// Human-readable NFIQ quality label (1=best, 5=worst).
        /// </summary>
        public static string NfiqLabel(int nfiq)
        {
            switch (nfiq)
            {
                case 1:  return "Excellent";
                case 2:  return "Very Good";
                case 3:  return "Good";
                case 4:  return "Fair";
                default: return "Poor";
            }
        }
    }
}
