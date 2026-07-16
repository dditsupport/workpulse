using System;
using System.Collections.Generic;
using System.Linq;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Core.Models;

namespace BiometricAttendance.Core.Services
{
    /// <summary>
    /// Manages in-memory employee state and punch validation.
    /// Score comparison always uses NORMALIZED scores (0–100).
    ///
    /// Threshold resolution:
    ///   Tier 1 → employee.MatchThreshold (not null)
    ///   Tier 2 → _globalThreshold (from system_settings, not null)
    ///   Tier 3 → HARDCODED_FALLBACK = 50
    /// </summary>
    public class AttendanceService
    {
        // ── Tier 3: hardcoded last-resort (matches old raw 500 on MFS500) ─────
        private const int HardcodedFallbackThreshold      = 55;
        private const int HardcodedEnrollDuplicateThreshold = 60;

        // ── Tier 2: global threshold from system_settings ─────────────────────
        private int? _globalThreshold;       // null until set from server
        private int  _enrollDupThreshold = HardcodedEnrollDuplicateThreshold;
        private int  _minPunchIntervalMinutes = 1;

        private readonly List<Employee> _employees = new List<Employee>();
        private readonly object         _lock      = new object();

        // ── Apply server settings on startup ─────────────────────────────────
        public void ApplyServerSettings(
            int  minPunchIntervalMinutes,
            int? globalAttendanceThreshold,
            int? enrollDuplicateThreshold)
        {
            if (minPunchIntervalMinutes    > 0)   _minPunchIntervalMinutes = minPunchIntervalMinutes;
            if (globalAttendanceThreshold.HasValue) _globalThreshold       = globalAttendanceThreshold;
            if (enrollDuplicateThreshold.HasValue)  _enrollDupThreshold    = enrollDuplicateThreshold.Value;
        }

        // =====================================================================
        // THRESHOLD RESOLUTION
        // =====================================================================

        /// <summary>
        /// Resolves effective threshold for an employee (normalized 0–100).
        /// Always returns a value — Tier 3 hardcoded fallback is last resort.
        /// </summary>
        public int GetEffectiveThreshold(Employee emp)
        {
            // Tier 1
            if (emp?.MatchThreshold.HasValue == true)
                return emp.MatchThreshold.Value;

            // Tier 2
            if (_globalThreshold.HasValue)
                return _globalThreshold.Value;

            // Tier 3
            return HardcodedFallbackThreshold;
        }

        // =====================================================================
        // LOAD / GET EMPLOYEES
        // =====================================================================

        public void LoadEmployees(List<Employee> employees)
        {
            lock (_lock)
            {
                _employees.Clear();
                _employees.AddRange(employees);
            }
        }

        public Employee GetByCode(string code)
        {
            if (string.IsNullOrWhiteSpace(code)) return null;
            lock (_lock)
                return _employees.FirstOrDefault(
                    e => e.EmployeeCode.Equals(code.Trim(), StringComparison.OrdinalIgnoreCase));
        }

        public List<Employee> GetAll()
        {
            lock (_lock) return _employees.ToList();
        }

        // =====================================================================
        // FINGERPRINT VERIFY — 1:1 match for a given device type
        // normalizedScore = ScoreHelper.Normalize(rawScore, deviceType)
        // Returns: (matched, normalizedScore, effectiveThreshold)
        // =====================================================================
        public (bool matched, int normalizedScore, int threshold) VerifyFingerprint(
            Employee                    emp,
            byte[]                      capturedTemplate,
            DeviceType                  deviceType,
            Func<byte[], byte[], int>   rawMatchFunc)
        {
            if (emp == null)
                throw new ArgumentNullException(nameof(emp));

            if (!emp.HasTemplate(deviceType))
                throw new InvalidOperationException(
                    $"Employee {emp.EmployeeCode} has no {deviceType} template.");

            int rawScore        = rawMatchFunc(capturedTemplate, emp.GetTemplate(deviceType));
            int normalizedScore = ScoreHelper.Normalize(rawScore, deviceType);
            int threshold       = GetEffectiveThreshold(emp);

            return (normalizedScore >= threshold, normalizedScore, threshold);
        }

        // =====================================================================
        // ENROLLMENT DUPLICATE CHECK
        // Checks captured template against all enrolled employees of same device.
        // Throws if duplicate found. Skips the employee being re-enrolled.
        // rawMatchFunc returns RAW score.
        // =====================================================================
        /// <summary>
        /// Checks new template against all active enrolled employees of the same device type.
        /// onProgress(checked, total) is called after each comparison — caller marshals to UI thread.
        /// </summary>
        public void CheckDuplicate(
            string                    enrollingCode,
            byte[]                    newTemplate,
            DeviceType                deviceType,
            Func<byte[], byte[], int> rawMatchFunc,
            Action<int, int>          onProgress = null)
        {
            List<Employee> candidates;
            lock (_lock)
            {
                candidates = _employees
                    .Where(e => e.EmployeeCode != enrollingCode && e.HasTemplate(deviceType))
                    .ToList();
            }

            int total   = candidates.Count;
            int checkedCount = 0;

            foreach (var emp in candidates)
            {
                int rawScore        = rawMatchFunc(newTemplate, emp.GetTemplate(deviceType));
                int normalizedScore = ScoreHelper.Normalize(rawScore, deviceType);
                checkedCount++;
                onProgress?.Invoke(checkedCount, total);

                if (normalizedScore >= _enrollDupThreshold)
                    throw new DuplicateFingerprintException(
                        emp.EmployeeCode, emp.FullName, normalizedScore);
            }
        }

        // =====================================================================
        // VALIDATE PUNCH — sequence and interval rules
        // Returns null = OK to punch, non-null = error message for UI
        // =====================================================================
        public string ValidatePunch(Employee emp, string punchType)
        {
            if (emp  == null)                              return "Employee not found";
            if (punchType != "IN" && punchType != "OUT")  return "Invalid punch type";

            if (emp.LastPunchTime.HasValue && string.IsNullOrEmpty(emp.LastPunchType))
                return "Punch record is corrupted — please contact admin";
            
            if (!emp.LastPunchTime.HasValue)
            {
                if (punchType == "OUT") return "Please punch IN first";
                return null;
            }
            
            // 4AM shift-day cutoff — matches server (see attendance.php).
            bool   sameShiftDay = ShiftHelper.ShiftDay(emp.LastPunchTime.Value)
                                  == ShiftHelper.ShiftDay(DateTime.Now);
            double minutesDiff  = (DateTime.Now - emp.LastPunchTime.Value).TotalMinutes;

            if (sameShiftDay)
            {
                if (minutesDiff < _minPunchIntervalMinutes)
                    return $"Please wait {_minPunchIntervalMinutes} minute(s) before next punch";

                if (punchType == "IN"  && emp.LastPunchType == "IN")  return "Already punched IN today";
                if (punchType == "OUT" && emp.LastPunchType != "IN")  return "Cannot punch OUT before IN";
            }
            else
            {
                if (punchType == "OUT") return "Please punch IN first for today";
            }

            return null;
        }

        // =====================================================================
        // REGISTER PUNCH — update in-memory state after server confirms save
        // =====================================================================
        public void RegisterPunch(
            Employee emp, string punchType, int normalizedScore,
            string deviceSerial, DeviceType deviceType, int locationId,
            string punchMethod = "fingerprint")
        {
            if (emp == null) return;
            lock (_lock)
            {
                var now = DateTime.Now;
                emp.LastPunchTime = now;
                emp.LastPunchType = punchType;

                emp.PunchHistory.Insert(0, new AttendanceRecord
                {
                    EmployeeCode = emp.EmployeeCode,
                    DeviceSerial = deviceSerial,
                    DeviceType   = deviceType,
                    LocationId   = locationId,
                    PunchType    = punchType,
                    PunchMethod  = punchMethod,
                    MatchScore   = normalizedScore,
                    Timestamp    = now,
                });

                // Keep last 2 punches in memory.
                while (emp.PunchHistory.Count > 2)
                    emp.PunchHistory.RemoveAt(emp.PunchHistory.Count - 1);
            }
        }
    }

    // =========================================================================
    // CUSTOM EXCEPTIONS
    // =========================================================================

    public class DuplicateFingerprintException : Exception
    {
        public string MatchedCode  { get; }
        public string MatchedName  { get; }
        public int    MatchedScore { get; }

        public DuplicateFingerprintException(string code, string name, int score)
            : base($"Duplicate fingerprint — matches: {code} ({name}), score: {score}")
        {
            MatchedCode  = code;
            MatchedName  = name;
            MatchedScore = score;
        }
    }
}
