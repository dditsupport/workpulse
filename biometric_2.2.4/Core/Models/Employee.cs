using System;
using System.Collections.Generic;
using BiometricAttendance.Core.Interfaces;

namespace BiometricAttendance.Core.Models
{
    public class Employee
    {
        // ── Identity ──────────────────────────────────────────────────────────
        public string EmployeeCode { get; set; }
        public string FullName     { get; set; }
        public string Department   { get; set; }
        public string Phone        { get; set; }
        public string Email        { get; set; }

        // ── Templates: one per device type (null = not enrolled on that device)
        public byte[] TemplateMfs500 { get; set; }   // ANSI_V378
        public byte[] TemplateFm220  { get; set; }   // ISO

        // ── Threshold: normalized 0–100; null = fall through to global ────────
        public int? MatchThreshold { get; set; }

        // ── OTP ───────────────────────────────────────────────────────────────
        public string     OtpChannel { get; set; }   // "none" | "email" | "sms"
        public bool       OtpEnabled =>
            !string.IsNullOrEmpty(OtpChannel) && OtpChannel != "none";

        // ── Punch state (kept in memory, restored from last_punch on startup) ─
        public DateTime? LastPunchTime { get; set; }
        public string    LastPunchType { get; set; }  // "IN" | "OUT"

        // ── Punch history (last 2, in memory only) ────────────────────────────
        public List<AttendanceRecord> PunchHistory { get; set; }
            = new List<AttendanceRecord>();

        // ── Helpers ───────────────────────────────────────────────────────────

        /// <summary>
        /// Returns template bytes for the given device type.
        /// Returns null if employee has no template for that device.
        /// </summary>
        public byte[] GetTemplate(DeviceType deviceType)
        {
            return deviceType == DeviceType.MFS500
                ? TemplateMfs500
                : TemplateFm220;
        }

        /// <summary>
        /// True if employee has a template for the given device type.
        /// </summary>
        public bool HasTemplate(DeviceType deviceType)
        {
            var t = GetTemplate(deviceType);
            return t != null && t.Length > 0;
        }
    }
}
