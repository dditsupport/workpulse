using System;
using System.Drawing;
using BiometricAttendance.Core.Interfaces;
using MorFin_Auth;

namespace BiometricAttendance.Devices
{
    /// <summary>
    /// Wraps MorFin_Auth.dll for MFS500 fingerprint scanner.
    /// Raw score range: 0–1000. Template format: ANSI_V378.
    /// </summary>
    public class Mfs500Device : IFingerprintDevice
    {
        private MorFinAuth        _auth;
        private FINGER_DEVICE_INFO _deviceInfo;
        private bool              _initialized;

        public DeviceType DeviceType   => DeviceType.MFS500;
        public string     DeviceSerial { get; private set; } = string.Empty;

        public event Action<Bitmap> OnPreview;

        /// <summary>MFS500 does not produce progress messages; event never fires.</summary>
        public event Action<string> OnProgressMessage;  //check after update

        /// <summary>MFS500 preview uses bitmap callbacks; this is a no-op.</summary>
        public void SetPreviewHandle(IntPtr handle) { /* MFS500 uses OnPreview callbacks */ }  //check after update

        // ── Initialize ────────────────────────────────────────────────────────
        public void Initialize()
        {
            _auth = new MorFinAuth();
            _auth.OnPreview += PreviewHandler;      //changed + to -

            _deviceInfo = new FINGER_DEVICE_INFO();
            int ret = _auth.Init("MFS500", ref _deviceInfo, "");

            if (ret != 0)
                throw new Exception("MFS500 Init failed: " + _auth.GetErrDescription(ret));

            DeviceSerial = _deviceInfo.SerialNo ?? "UNKNOWN";
            _initialized = true;
        }

        // ── Shutdown ──────────────────────────────────────────────────────────
        public void Shutdown()
        {
            if (!_initialized) return;
            try
            {
                _auth.OnPreview -= PreviewHandler;
                // MorFin_Auth does not expose an explicit close method;
                // releasing the reference is sufficient.
                _auth        = null;
                _initialized = false;
            }
            catch { /* ignore cleanup errors */ }
        }

        // ── Capture ───────────────────────────────────────────────────────────
        public CaptureResult? CaptureTemplate()
        {
            EnsureInit();

            // AutoCapture blocks until finger is placed or timeout (10s, min quality 60)
            int ret = _auth.AutoCapture(out int quality, out int nfiq, 10000, 60);

            if (ret != 0)
            {
                string err = _auth.GetErrDescription(ret);
                if (!string.IsNullOrEmpty(err) &&
                    err.IndexOf("timeout", StringComparison.OrdinalIgnoreCase) >= 0)
                    return null;    // timeout = normal, not an error

                throw new Exception("MFS500 capture error: " + err);
            }

            ret = _auth.GetTemplate(out byte[] template, TEMPLATE_FORMAT.ANSI_V378, 5);
            if (ret != 0)
                throw new Exception("MFS500 GetTemplate error: " + _auth.GetErrDescription(ret));

            return new CaptureResult
            {
                Template = template,
                Quality  = quality,
                Nfiq     = nfiq,
            };
        }

        // ── Match — raw score 0–1000 ──────────────────────────────────────────
        public int Match(byte[] template1, byte[] template2)
        {
            EnsureInit();

            int ret = _auth.MatchTemplate(
                template1, template1.Length,
                template2, template2.Length,
                out int score,
                TEMPLATE_FORMAT.ANSI_V378);

            if (ret != 0)
                throw new Exception("MFS500 Match error: " + _auth.GetErrDescription(ret));

            return score;  // 0–1000
        }

        // ── Preview callback ──────────────────────────────────────────────────
        private void PreviewHandler(CaptureData data)
        {
            if (data?.AutoCaptureBitmap == null) return;

            Delegate[] subscribers = OnPreview?.GetInvocationList();
            if (subscribers == null || subscribers.Length == 0) return;

            foreach (Delegate subscriber in subscribers)
            {
                try
                {
                    // Each subscriber gets its own Bitmap so they can be
                    // disposed independently without affecting the others.
                    Bitmap copy = new Bitmap(data.AutoCaptureBitmap);
                    ((Action<Bitmap>)subscriber)(copy);
                }
                catch { /* ignore per-subscriber errors; continue to next */ }
            }
        }

        // ── Helpers ───────────────────────────────────────────────────────────
        private void EnsureInit()
        {
            if (!_initialized)
                throw new InvalidOperationException("MFS500 device is not initialized.");
        }

        public void Dispose() => Shutdown();
    }
}
