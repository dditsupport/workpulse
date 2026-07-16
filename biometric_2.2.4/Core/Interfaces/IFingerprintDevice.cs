using System;
using System.Drawing;

namespace BiometricAttendance.Core.Interfaces
{
    /// <summary>
    /// Abstracts all fingerprint device operations.
    /// Both MFS500 and FM220 implement this interface.
    /// All scores returned by Match() are RAW (device-native range).
    /// Normalization to 0–100 is done in ScoreHelper.
    ///
    /// Preview behaviour per device:
    ///   MFS500 → fires OnPreview(Bitmap) callbacks; SetPreviewHandle is a no-op.
    ///   FM220  → SDK paints live video directly to the hwnd set via SetPreviewHandle;
    ///            OnPreview is never fired. Call SetPreviewHandle(picPreview.Handle)
    ///            on the UI thread BEFORE calling CaptureTemplate().
    /// </summary>
    public interface IFingerprintDevice : IDisposable
    {
        // ── Identity ──────────────────────────────────────────────────────────
        DeviceType DeviceType { get; }
        string DeviceSerial { get; }

        // ── Events ────────────────────────────────────────────────────────────


        /// <summary>
        /// MFS500: fires continuously while scanner is active with live bitmap frames.
        /// FM220:  never fires — SDK draws directly to the hwnd handle.
        /// </summary>
        event Action<Bitmap> OnPreview;

        /// <summary>
        /// Fires with SDK status strings during capture.
        /// FM220 example messages: "Place finger on sensor", "Keep finger still",
        /// "Finger too dry", "Scanning…"
        /// MFS500 does not typically fire this.
        /// UI should display these messages as instructions to the operator.
        /// </summary>
        event Action<string> OnProgressMessage;

        // ── Lifecycle ─────────────────────────────────────────────────────────
        void Initialize();
        void Shutdown();

        // ── Preview handle (FM220 only) ───────────────────────────────────────
        /// <summary>
        /// FM220: call this on the UI thread with picPreview.Handle BEFORE
        /// calling CaptureTemplate(). The SDK will paint live preview frames
        /// directly into that control window.
        /// MFS500: no-op.
        /// </summary>
        void SetPreviewHandle(IntPtr handle);

        // ── Capture ───────────────────────────────────────────────────────────

        /// <summary>
        /// Blocks until a finger is placed and captured, or times out.
        /// Returns null on timeout (not an error).
        /// Throws on device error.
        /// </summary>
        CaptureResult? CaptureTemplate();

        // ── Match ─────────────────────────────────────────────────────────────

        /// <summary>

        /// Returns RAW score in device-native range:
        ///   MFS500 → 0–1000   (normalize: raw / 10)
        ///   FM220  → 0–100    (no normalization needed)

        /// </summary>
        int Match(byte[] template1, byte[] template2);
    }

    // ── Enums / structs ───────────────────────────────────────────────────────

    /// <summary>
    /// Thrown when a device's native matcher returns a non-success STATUS instead of a
    /// comparable score. This means the match OPERATION failed (too few minutiae in the
    /// probe, or a template format/version mismatch) — NOT that the fingerprint scored
    /// low/zero. Callers must log this distinctly, NOT as 'score_below_threshold'.
    /// </summary>
    public class FingerprintMatchException : Exception
    {
        public int StatusCode { get; }   // raw device-native status; non-zero = error
        public FingerprintMatchException(int statusCode, string message)
            : base(message) { StatusCode = statusCode; }
    }

    public enum DeviceType { MFS500, FM220 }





    public struct CaptureResult
    {
        /// <summary>Raw template bytes in device-native format.</summary>
        public byte[] Template { get; set; }

        /// <summary>Capture quality 0–100 (higher = better).</summary>
        public int Quality { get; set; }

        /// <summary>NFIQ: 1 = Excellent, 2 = Very Good, 3 = Good, 4 = Fair, 5 = Poor.</summary>
        public int Nfiq { get; set; }
    }
}
