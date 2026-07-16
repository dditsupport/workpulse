using System;
using System.Drawing;
using System.IO;
using System.Threading;
using BiometricAttendance.Core.Interfaces;
using ACPLFM220SDKCS;

namespace BiometricAttendance.Devices
{
    /// <summary>
    /// Wraps ACPLFM220SDKCS.dll for FM220 fingerprint scanner.
    ///
    /// PREVIEW:
    ///   The FM220 SDK draws live finger video directly into a Windows handle
    ///   (hwnd) passed to CaptureFM220(). Call SetPreviewHandle(picPreview.Handle)
    ///   on the UI thread before CaptureTemplate(). The PictureBox will show
    ///   live preview without any bitmap callbacks from this class.
    ///
    /// RAW SCORE RANGE: 0–100 (no normalization needed for FM220).
    /// TEMPLATE FORMAT: ISO.
    /// </summary>
    public class Fm220Device : IFingerprintDevice, FM220_Scanner_Interface
    {
        private FM220_SDK_Main _sdk;
        private bool _initialized;

        // Capture synchronisation
        private readonly ManualResetEventSlim _captureReady = new ManualResetEventSlim(false);
        private readonly object _captureLock = new object();

        // Per-capture token: every CaptureTemplate() call gets a fresh Guid.
        // ScanCompleteFM220 only honours a callback whose token matches the
        // currently-armed capture, so a late callback from a previous (timed-out)
        // capture cannot stomp on fresh state — which used to surface as
        // "previous user's template returned for a new scan".
        private Guid _currentCaptureToken;
        private CaptureResult? _lastCapture;
        private Exception _lastError;

        // Preview handle — set by caller before capture
        private IntPtr _previewHandle = IntPtr.Zero;
        // The FM220 SDK does NOT reset its internal state machine to idle after
        // ScanCompleteFM220 fires. On the next capture call we must do a
        // unInitFM220 + initFM220Scanner cycle to force the SDK back to idle,
        // otherwise CaptureFM220 immediately returns "Capture is already in
        // progress" via a fresh error callback, causing CaptureTemplate to throw.
        private bool _captureEverStarted;
        public DeviceType DeviceType => DeviceType.FM220;
        public string DeviceSerial { get; private set; } = string.Empty;

        // MFS500-style bitmap preview — FM220 never fires this.
        public event Action<Bitmap> OnPreview;

        // SDK status / instruction messages ("Place finger", "Keep still", etc.)
        public event Action<string> OnProgressMessage;

        // ── Lifecycle ─────────────────────────────────────────────────────────
        public void Initialize()
        {
            _sdk = new FM220_SDK_Main(this);
            FM220_Init_Result result = _sdk.initFM220Scanner();

            if (!result.getResult())
                throw new Exception("FM220 Init failed: " + result.getError());

            DeviceSerial = result.getSerialNo() ?? "UNKNOWN";
            _initialized = true;
            _captureEverStarted = false;
        }

        public void Shutdown()
        {
            if (!_initialized) return;
            try { _sdk.unInitFM220(); }
            catch { }
            _initialized = false;
            _captureEverStarted = false;

            // Disarm any in-flight capture so a late callback can't post stale
            // data into a freshly-armed capture after re-init.
            lock (_captureLock)
            {
                _currentCaptureToken = Guid.Empty;
                _lastCapture = null;
                _lastError = null;
                _captureReady.Set();
            }
        }

        // ── Preview handle ────────────────────────────────────────────────────
        public void SetPreviewHandle(IntPtr handle) => _previewHandle = handle;

        // ── Capture ───────────────────────────────────────────────────────────
        // [HandleProcessCorruptedStateExceptions] + [SecurityCritical] are
        // required (alongside <legacyCorruptedStateExceptionsPolicy enabled="true"/>
        // in App.config) so a fault inside the FM220 native DLL — most
        // notably System.AccessViolationException raised by
        // FM220_native.FP_Capture(m_hConnect, hFPCapture) when the device
        // handle is stale — can be caught and surfaced as a managed error
        // instead of killing the kiosk process.
        [System.Runtime.ExceptionServices.HandleProcessCorruptedStateExceptions]
        [System.Security.SecurityCritical]
        public CaptureResult? CaptureTemplate()
        {
            EnsureInit();

            if (_captureEverStarted)
            {
                try { _sdk.unInitFM220(); }
                catch { /* best-effort */ }

                // Brief settle delay between unInit and init — the native
                // USB layer needs a moment to release the device handle.
                // Without it, the back-to-back pair can crash unmanaged
                // code (Windows "BiometricAttendance.exe has stopped
                // working") especially after a recent failed/timed-out
                // capture left the SDK in a fragile state.
                System.Threading.Thread.Sleep(150);

                FM220_Init_Result reinit = _sdk.initFM220Scanner();
                if (!reinit.getResult())
                    throw new Exception("FM220 reinit failed: " + reinit.getError());

                string serial = reinit.getSerialNo();
                if (!string.IsNullOrEmpty(serial))
                    DeviceSerial = serial;
            }

            // Arm the capture: fresh token, clear state, reset wait handle.
            Guid myToken;
            lock (_captureLock)
            {
                myToken = Guid.NewGuid();
                _currentCaptureToken = myToken;
                _lastCapture = null;
                _lastError = null;
                _captureReady.Reset();
            }

            // Guard the native capture entry point. CaptureFM220 internally
            // does num = FM220_native.FP_Capture(m_hConnect, hFPCapture);
            // which AVs on a stale m_hConnect. Catching here lets us treat
            // it as a recoverable scan failure (force-reset the SDK on the
            // next call by leaving _captureEverStarted = true), instead of
            // letting the runtime tear down the process.
            try
            {
                _sdk.CaptureFM220(true, true, ref _previewHandle);
            }
            catch (AccessViolationException ave)
            {
                // SDK is in a fundamentally bad state — disarm the wait
                // handle and surface a managed error. The catch in
                // MainForm.StartCaptureAsync will show "Capture error: …"
                // and re-enable the Scan button; the next click runs the
                // start-of-method unInit+init pair (because we still leave
                // _captureEverStarted = true) which should re-open a fresh
                // device handle.
                lock (_captureLock) { _currentCaptureToken = Guid.Empty; }
                _captureEverStarted = true;
                throw new Exception(
                    "FM220 sensor crashed (access violation in native FP_Capture). "
                    + "The device has been reset — please try again.", ave);
            }
            _captureEverStarted = true;

            // Wait up to 12 seconds for a result.
            bool signalled = _captureReady.Wait(12000);

            CaptureResult? capture;
            Exception      error;
            lock (_captureLock)
            {
                // Disarm: any callback that arrives after this point is ignored.
                if (_currentCaptureToken == myToken) _currentCaptureToken = Guid.Empty;
                capture = _lastCapture;
                error   = _lastError;
                _lastCapture = null;
                _lastError = null;
            }

            if (!signalled)
            {
                // Timeout: force the SDK back to idle so the next capture
                // starts clean, and discard any in-flight callback
                // (already disarmed by token clear).
                //
                // Sleep between unInit and init — the native USB layer
                // needs a moment to release the device handle. Skipping
                // it has been observed to AV inside FP_Capture next time.
                //
                // We intentionally LEAVE _captureEverStarted = true so the
                // next CaptureTemplate() does its own unInit+init pair
                // before calling CaptureFM220. An earlier attempt to set
                // it false here (to dedupe the re-init) caused
                // System.AccessViolationException in
                // FM220_native.FP_Capture(m_hConnect, hFPCapture) — the
                // timeout-path's init is wrapped in try/catch, so if it
                // silently fails the SDK is left with a dead m_hConnect
                // and the next CaptureFM220 dereferences freed memory.
                // The duplicate init on the next call is wasteful but
                // catchable on failure; the dead-handle crash is not.
                try { _sdk.unInitFM220(); } catch { }
                System.Threading.Thread.Sleep(150);
                try { _sdk.initFM220Scanner(); } catch { }
                return null;
            }

            if (error != null) throw error;
            return capture;
        }

        // ── Match ─────────────────────────────────────────────────────────────
        /// <summary>Returns raw score 0–100 (FM220 native range).</summary>
        public int Match(byte[] template1, byte[] template2)
        {
            // Defensive input guard — the native SDK's matchFingerTamplates()
            // P/Invoke crashes unmanaged code (uncatchable as a managed
            // exception, surfaces as the "BiometricAttendance.exe has stopped
            // working" Windows dialog) when handed a null/empty template.
            // The captured template can legitimately be empty if the user
            // pressed too faintly — see ScanCompleteFM220 below.
            if (template1 == null || template1.Length == 0)
                throw new Exception("Cannot match — captured fingerprint template is empty (image too faint?).");
            if (template2 == null || template2.Length == 0)
                throw new Exception("Cannot match — stored fingerprint template is empty.");
            EnsureInit();
            byte[] t1 = template1;
            byte[] t2 = template2;
            int[] res = _sdk.matchFingerTamplates(ref t1, ref t2);

            // Defensive: SDK returning null or a short array is rare but happens
            // on hardware faults; surface a clear error instead of an opaque
            // IndexOutOfRangeException upstream.
            if (res == null || res.Length < 2)
                throw new Exception("FM220 match returned malformed result");

            // res[0] = SDK status (0 = success); res[1] = match score. The old code
            // collapsed ANY non-zero status into a score of 0, which MainForm then
            // logged as 'score_below_threshold, match_score=0' — conflating a failed
            // match OPERATION with a genuine zero similarity. Mirror
            // Mfs500Device.Match() and throw instead.
            if (res[0] != 0)
                throw new FingerprintMatchException(
                    res[0],
                    "FM220 matcher error status " + res[0] +
                    " (probe likely had too few minutiae, or a template format/version mismatch).");
            return res[1];
        }

        // ── FM220_Scanner_Interface callbacks ─────────────────────────────────

        // [HandleProcessCorruptedStateExceptions] + [SecurityCritical] are
        // required (alongside <legacyCorruptedStateExceptionsPolicy enabled="true"/>
        // in App.config) so a fault inside the SDK's image cleanup —
        // notably AccessViolationException raised by
        // FM220_native.FP_DestroyImageHandle(m_hConnect, hFPImage)
        // when result.getISO_Template() triggers the SDK's free path
        // — can be caught and surfaced as a managed error instead of
        // killing the kiosk process from this callback thread.
        [System.Runtime.ExceptionServices.HandleProcessCorruptedStateExceptions]
        [System.Security.SecurityCritical]
        public void ScanCompleteFM220(FM220_Capture_Result result)
        {
            // Discard callbacks from a previous (timed-out) capture: they would
            // otherwise overwrite the now-disarmed state and either leak the
            // previous user's template or look like the wrong error.
            lock (_captureLock)
            {
                if (_currentCaptureToken == Guid.Empty) return;
            }

            CaptureResult? capture = null;
            Exception      error   = null;

            if (result.getResult())
            {
                byte[] template;
                try
                {
                    // getISO_Template internally calls FP_DestroyImageHandle
                    // after extracting the template — that's where the AV
                    // happens when the device handle has gone stale.
                    template = result.getISO_Template();
                }
                catch (AccessViolationException ave)
                {
                    error = new Exception(
                        "FM220 sensor crashed during image cleanup (access violation in FP_DestroyImageHandle). "
                        + "The device has been reset — please try again.", ave);
                    lock (_captureLock)
                    {
                        if (_currentCaptureToken == Guid.Empty) return;
                        _lastCapture = null;
                        _lastError = error;
                        _captureReady.Set();
                    }
                    return;
                }

                // The SDK reports success but returns a null / empty
                // template when the captured image is too faint or
                // partial. Forwarding that straight into matchFingerTamplates()
                // crashes the host process in unmanaged code. Surface it
                // as a low-quality CaptureResult instead — the quality-gated
                // capture loop in MainForm then retries with the standard
                // "adjust finger and try again" message and falls through
                // to "Could not capture clear image" after MaxCaptureAttempts,
                // exactly like a normal poor-quality print. No exception,
                // no broken retry path, no app close.
                if (template == null || template.Length == 0)
                {
                    capture = new CaptureResult
                    {
                        Template = Array.Empty<byte>(),
                        Quality  = 0,
                        Nfiq     = 5, // "Poor" — guaranteed > AcceptableNfiq (3) so the loop retries.
                    };
                }
                else
                {
                    int nfiq = result.getNFIQ();
                    string serial = result.getSerialNo();

                    if (!string.IsNullOrEmpty(serial))
                        DeviceSerial = serial;

                    int quality = NfiqToQuality(nfiq);

                    capture = new CaptureResult
                    {
                        Template = template,
                        Quality = quality,
                        Nfiq = nfiq,
                    };
                }
            }
            else
            {
                error = new Exception("FM220 scan failed: " + result.getError());
            }

            lock (_captureLock)
            {
                if (_currentCaptureToken == Guid.Empty) return; // disarmed during the check
                _lastCapture = capture;
                _lastError = error;
                _captureReady.Set();
            }
        }

        public void ScannerProgressFM220(bool displayText, string statusMessage)
        {
            // We used to skip events with displayText == false. The kiosk
            // was then only seeing the SDK's initial "place finger" event
            // and nothing afterwards — the SDK actually fires more
            // events through this callback during a scan (state changes
            // like wet/dry/light contact), but most of them come with
            // displayText = false. Surfacing them all gives the
            // live image-quality readout something to update on.
            if (!string.IsNullOrWhiteSpace(statusMessage))
                OnProgressMessage?.Invoke(statusMessage);
        }

        // ── Helpers ───────────────────────────────────────────────────────────

        private static int NfiqToQuality(int nfiq)
        {
            switch (nfiq)
            {
                case 1: return 95;
                case 2: return 80;
                case 3: return 65;
                case 4: return 45;
                default: return 20;
            }
        }

        private void EnsureInit()
        {
            if (!_initialized)
                throw new InvalidOperationException("FM220 device is not initialized.");
        }

        public void Dispose() => Shutdown();
    }
}
