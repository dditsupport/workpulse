using System;
using System.Collections.Generic;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using BiometricAttendance.Core.Interfaces;
using BiometricAttendance.Core.Models;
using Newtonsoft.Json;

namespace BiometricAttendance.Core.Services
{
    /// <summary>
    /// All HTTP communication with the WorkPulse api/ surface.
    /// Constructed once and shared across forms.
    /// </summary>
    public class ApiService : IDisposable
    {
        private readonly HttpClient _client;
        private readonly DeviceType _deviceType;

        public ApiService(string baseUrl, string apiKey, DeviceType deviceType)
        {
            _deviceType = deviceType;

            _client = new HttpClient
            {
                BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/"),
                Timeout = TimeSpan.FromSeconds(20),
            };
            _client.DefaultRequestHeaders.Clear();
            _client.DefaultRequestHeaders.Add("X-API-KEY", apiKey);
            _client.DefaultRequestHeaders.Add("User-Agent", "BiometricAttendance/2.2");
            _client.DefaultRequestHeaders.Add("Accept", "application/json");
        }

        // =====================================================================
        // ADMIN LOGIN (kiosk admin password verified server-side)
        // =====================================================================
        public async Task<ApiResult> AdminLoginAsync(string password)
        {
            var payload = new { password = password ?? "" };
            try
            {
                var response = await PostJsonAsync("api/admin_login.php", payload);
                var json     = await response.Content.ReadAsStringAsync();
                var result   = Deserialize<GenericResponse>(json);

                return new ApiResult
                {
                    Success      = response.IsSuccessStatusCode && result?.success == true,
                    ErrorMessage = result?.message,
                    StatusCode   = (int)response.StatusCode,
                };
            }
            catch (Exception ex)
            {
                return new ApiResult { Success = false, ErrorMessage = ex.Message };
            }
        }

        // =====================================================================
        // GET ACTIVE EMPLOYEES (with template for configured device type)
        // =====================================================================
        public async Task<List<Employee>> GetEmployeesAsync()
        {
            var response = await _client.GetAsync(
                $"api/f_employee.php?device_type={_deviceType}");

            await EnsureSuccessAsync(response);

            var json = await response.Content.ReadAsStringAsync();
            var result = Deserialize<EmployeeListResponse>(json);

            if (result?.data == null) return new List<Employee>();

            var employees = new List<Employee>();
            foreach (var d in result.data)
            {
                try
                {
                    if (string.IsNullOrWhiteSpace(d.template_base64)) continue;

                    var emp = new Employee
                    {
                        EmployeeCode = d.employee_code,
                        FullName = d.full_name,
                        Department = d.department ?? "",
                        Phone = d.phone ?? "",
                        Email = d.email ?? "",
                        MatchThreshold = d.match_threshold,
                        OtpChannel = d.otp_channel,
                    };

                    string b64 = (d.template_base64 ?? "").Trim();
                    if (b64.Length < 20) continue;
                    var templateBytes = Convert.FromBase64String(b64);

                    if (_deviceType == DeviceType.MFS500)
                        emp.TemplateMfs500 = templateBytes;
                    else
                        emp.TemplateFm220 = templateBytes;

                    employees.Add(emp);
                }
                catch { /* skip corrupted record */ }
            }
            return employees;
        }

        // =====================================================================
        // GET PENDING EMPLOYEES (missing template for configured device type)
        // =====================================================================
        public async Task<List<Employee>> GetPendingEmployeesAsync()
        {
            try
            {
                var response = await _client.GetAsync(
                    $"api/pending_employees.php?device_type={_deviceType}");

                if (!response.IsSuccessStatusCode) return new List<Employee>();

                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<PendingListResponse>(json);

                if (result?.data == null) return new List<Employee>();

                var list = new List<Employee>();
                foreach (var d in result.data)
                {
                    list.Add(new Employee
                    {
                        EmployeeCode = d.employee_code,
                        FullName = d.full_name,
                        Department = d.department ?? "",
                        Phone = d.phone ?? "",
                    });
                }
                return list;
            }
            catch { return new List<Employee>(); }
        }

        // =====================================================================
        // GET EMPLOYEE BY CODE — single-record lookup, no template payload
        // implied by the call site (still respects f_employee's template gate).
        // Returns null if not found or no template.
        // Callers should hit the in-memory cache first; this is the network
        // fallback when the cache misses.
        // =====================================================================
        public async Task<Employee> GetEmployeeByCodeAsync(string employeeCode)
        {
            if (string.IsNullOrWhiteSpace(employeeCode)) return null;
            var code = employeeCode.Trim();

            try
            {
                var url = $"api/f_employee.php?device_type={_deviceType}" +
                          $"&employee_code={Uri.EscapeDataString(code)}";
                var response = await _client.GetAsync(url);
                if (!response.IsSuccessStatusCode) return null;

                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<EmployeeListResponse>(json);
                if (result?.data == null || result.data.Count == 0) return null;

                var d = result.data[0];
                var emp = new Employee
                {
                    EmployeeCode   = d.employee_code,
                    FullName       = d.full_name,
                    Department     = d.department ?? "",
                    Phone          = d.phone ?? "",
                    Email          = d.email ?? "",
                    MatchThreshold = d.match_threshold,
                    OtpChannel     = d.otp_channel,
                };

                string b64 = (d.template_base64 ?? "").Trim();
                if (b64.Length >= 20)
                {
                    try
                    {
                        var bytes = Convert.FromBase64String(b64);
                        if (_deviceType == DeviceType.MFS500) emp.TemplateMfs500 = bytes;
                        else                                  emp.TemplateFm220 = bytes;
                    }
                    catch { /* keep emp without template */ }
                }
                return emp;
            }
            catch { return null; }
        }

        // =====================================================================
        // ENROLL FINGER — save template for current device type
        // =====================================================================
        public async Task<bool> EnrollFingerAsync(string employeeCode, byte[] templateBytes)
        {
            var payload = new
            {
                employee_code = employeeCode,
                device_type = _deviceType.ToString(),
                template_base64 = Convert.ToBase64String(templateBytes),
            };

            var response = await PostJsonAsync("api/enroll_finger.php", payload);
            var json = await response.Content.ReadAsStringAsync();
            var result = Deserialize<GenericResponse>(json);

            return response.IsSuccessStatusCode && result?.success == true;
        }

        // =====================================================================
        // SEND ATTENDANCE
        // =====================================================================
        public async Task<ApiResult> SendAttendanceAsync(
            string employeeCode,
            string deviceSerial,
            int locationId,
            string punchType,
            string punchMethod,
            int normalizedScore)
        {
            var payload = new
            {
                employee_code = employeeCode,
                device_serial = deviceSerial,
                device_type = _deviceType.ToString(),
                location_id = locationId,
                punch_type = punchType,
                punch_method = punchMethod,
                match_score = normalizedScore,
            };

            try
            {
                var response = await PostJsonAsync("api/attendance.php", payload);
                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<GenericResponse>(json);

                return new ApiResult
                {
                    Success = response.IsSuccessStatusCode && result?.success == true,
                    ErrorMessage = result?.message,
                    StatusCode = (int)response.StatusCode,
                };
            }
            catch (Exception ex)
            {
                return new ApiResult { Success = false, ErrorMessage = ex.Message };
            }
        }

        // =====================================================================
        // SEND FAILED PUNCH
        // =====================================================================
        public async Task SendFailedPunchAsync(
            string employeeCode,
            string deviceSerial,
            int locationId,
            string punchType,
            string punchMethod,
            int? normalizedScore,
            int? thresholdUsed,
            string failReason)
        {
            var payload = new
            {
                employee_code = employeeCode,
                device_serial = deviceSerial,
                device_type = _deviceType.ToString(),
                location_id = locationId,
                punch_type = punchType,
                punch_method = punchMethod,
                match_score = normalizedScore,
                threshold_used = thresholdUsed,
                fail_reason = failReason,
                // Tag the failed-punch row with the kiosk build string so
                // admins can correlate a wave of failures to a specific
                // version on the Failed Punches page.
                app_version = BiometricAttendance.App.Forms.MainForm.AppVersion,
            };

            try { await PostJsonAsync("api/failed_punch.php", payload); }
            catch { /* best-effort */ }
        }

        // =====================================================================
        // SEND OTP
        // =====================================================================
        public async Task<OtpSendResult> SendOtpAsync(
            string employeeCode,
            string deviceSerial,
            int locationId,
            string punchType)
        {
            var payload = new
            {
                employee_code = employeeCode,
                device_serial = deviceSerial,
                device_type = _deviceType.ToString(),
                location_id = locationId,
                punch_type = punchType,
            };

            try
            {
                var response = await PostJsonAsync("api/send_otp.php", payload);
                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<OtpSendResponse>(json);

                if (response.IsSuccessStatusCode && result?.success == true)
                {
                    DateTime? expiresAt = null;
                    if (!string.IsNullOrWhiteSpace(result.expires_at) &&
                        DateTime.TryParse(result.expires_at, out var dt))
                    {
                        expiresAt = dt;
                    }

                    return new OtpSendResult
                    {
                        Success    = true,
                        Channel    = result.channel,
                        MaskedTo   = result.masked_to,
                        ExpiresIn  = result.expires_in,
                        ExpiresAt  = expiresAt,
                    };
                }

                return new OtpSendResult
                {
                    Success      = false,
                    ErrorMessage = result?.message ?? "Failed to send OTP",
                    StatusCode   = (int)response.StatusCode,
                };
            }
            catch (Exception ex)
            {
                return new OtpSendResult { Success = false, ErrorMessage = ex.Message };
            }
        }


        // =====================================================================
        // VERIFY OTP — returns a richer result so the caller can distinguish
        // an invalid code (retryable) from a lockout (force re-send).
        // =====================================================================
        public async Task<OtpVerifyResult> VerifyOtpAsync(
            string employeeCode,
            string otp,
            string deviceSerial,
            int locationId,
            string punchType)
        {
            var payload = new
            {
                employee_code = employeeCode,
                otp = otp,
                device_serial = deviceSerial,
                device_type = _deviceType.ToString(),
                location_id = locationId,
                punch_type = punchType,
            };

            try
            {
                var response = await PostJsonAsync("api/verify_otp.php", payload);
                var json     = await response.Content.ReadAsStringAsync();
                var result   = Deserialize<OtpVerifyResponse>(json);

                bool ok = response.IsSuccessStatusCode && result?.success == true;
                bool locked = (response.StatusCode == (HttpStatusCode)429) ||
                              (result?.locked == true);

                return new OtpVerifyResult
                {
                    Success      = ok,
                    Locked       = !ok && locked,
                    ErrorMessage = result?.message,
                    StatusCode   = (int)response.StatusCode,
                };
            }
            catch (Exception ex)
            {
                return new OtpVerifyResult { Success = false, ErrorMessage = ex.Message };
            }
        }

        // =====================================================================
        // GET SYSTEM SETTINGS
        // =====================================================================
        public async Task<Dictionary<string, string>> GetSystemSettingsAsync()
        {
            try
            {
                var response = await _client.GetAsync("api/system_settings.php");
                if (!response.IsSuccessStatusCode)
                    return new Dictionary<string, string>();

                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<SystemSettingsResponse>(json);

                return result?.data ?? new Dictionary<string, string>();
            }
            catch { return new Dictionary<string, string>(); }
        }

        // =====================================================================
        // REPORT APP VERSION
        // Best-effort: never crashes startup.
        // =====================================================================
        public async Task<bool> ReportAppVersionAsync(string deviceSerial, string appVersion)
        {
            if (string.IsNullOrWhiteSpace(deviceSerial) || string.IsNullOrWhiteSpace(appVersion))
                return false;

            var payload = new
            {
                device_serial = deviceSerial,
                app_version = appVersion,
            };

            try
            {
                var response = await PostJsonAsync("api/update_device_version.php", payload);
                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<AppVersionResponse>(json);
                return response.IsSuccessStatusCode && result?.success == true;
            }
            catch { return false; }
        }


        // =====================================================================
        // GET LAST PUNCHES — scoped to the calling device's location server-side
        // =====================================================================
        public async Task<Dictionary<string, (string type, DateTime time)>>
            GetLastPunchesAsync(string deviceSerial)
        {
            var dict = new Dictionary<string, (string, DateTime)>();
            if (string.IsNullOrWhiteSpace(deviceSerial)) return dict;

            try
            {
                var url = "api/last_punch.php?device_serial=" +
                          Uri.EscapeDataString(deviceSerial);
                var response = await _client.GetAsync(url);
                if (!response.IsSuccessStatusCode) return dict;

                var json = await response.Content.ReadAsStringAsync();
                var result = Deserialize<LastPunchListResponse>(json);

                if (result?.data != null)
                    foreach (var item in result.data)
                        if (DateTime.TryParse(item.punch_time, out var dt))
                            dict[item.employee_code.Trim()] = (item.punch_type, dt);
            }
            catch { }
            return dict;
        }

        // =====================================================================
        // PRIVATE HELPERS
        // =====================================================================
        private async Task<HttpResponseMessage> PostJsonAsync(string endpoint, object payload)
        {
            var content = new StringContent(
                JsonConvert.SerializeObject(payload),
                Encoding.UTF8,
                "application/json");
            return await _client.PostAsync(endpoint, content);
        }


        private static async Task EnsureSuccessAsync(HttpResponseMessage response)
        {
            if (!response.IsSuccessStatusCode)
            {
                var body = await response.Content.ReadAsStringAsync();
                throw new Exception(
                    $"API error {(int)response.StatusCode}: {body}");
            }
        }

        // Wraps JsonException with a truncated body so server-shape mismatches
        // are diagnosable instead of looking like silent network failures.
        private static T Deserialize<T>(string json) where T : class
        {
            if (json == null) return null;
            try { return JsonConvert.DeserializeObject<T>(json); }
            catch (JsonException ex)
            {
                var preview = json.Length > 256 ? json.Substring(0, 256) + "…" : json;
                throw new Exception($"Bad JSON ({typeof(T).Name}): {ex.Message} — body: {preview}", ex);
            }
        }

        public void Dispose() => _client?.Dispose();

        // =====================================================================
        // RESPONSE DTOs
        // =====================================================================
#pragma warning disable IDE1006
        private class EmployeeListResponse
        {
            public bool success { get; set; }
            public List<EmpDto> data { get; set; }
        }
        private class EmpDto
        {
            public string employee_code { get; set; }
            public string full_name { get; set; }
            public string department { get; set; }
            public string phone { get; set; }
            public string email { get; set; }
            public string template_base64 { get; set; }
            public int? match_threshold { get; set; }
            public string otp_channel { get; set; }
        }
        private class PendingListResponse
        {
            public bool success { get; set; }
            public List<PendingDto> data { get; set; }
        }
        private class PendingDto
        {
            public string employee_code { get; set; }
            public string full_name { get; set; }
            public string department { get; set; }
            public string phone { get; set; }
            public string enrollment_status { get; set; }
        }
        private class GenericResponse
        {
            public bool success { get; set; }
            public string message { get; set; }
        }
        private class SystemSettingsResponse
        {
            public bool success { get; set; }
            public Dictionary<string, string> data { get; set; }
        }
        private class LastPunchListResponse
        {
            public bool success { get; set; }
            public List<LastPunchDto> data { get; set; }
        }
        private class LastPunchDto
        {
            public string employee_code { get; set; }
            public string punch_type { get; set; }
            public string punch_time { get; set; }
        }
        private class OtpSendResponse
        {
            public bool success { get; set; }
            public string message { get; set; }
            public string channel { get; set; }
            public string masked_to { get; set; }
            public int expires_in { get; set; }
            public string expires_at { get; set; }
        }
        private class OtpVerifyResponse
        {
            public bool success { get; set; }
            public string message { get; set; }
            public bool locked { get; set; }
        }
        private class AppVersionResponse
        {
            public bool success { get; set; }
            public bool updated { get; set; }
            public string app_version { get; set; }
            public string message { get; set; }
        }
#pragma warning restore IDE1006
    }

    public class OtpSendResult
    {
        public bool Success { get; set; }
        public string Channel { get; set; }
        public string MaskedTo { get; set; }
        public int ExpiresIn { get; set; }
        public DateTime? ExpiresAt { get; set; }
        public string ErrorMessage { get; set; }
        public int StatusCode { get; set; }
    }

    public class OtpVerifyResult
    {
        public bool Success { get; set; }
        public bool Locked { get; set; }
        public string ErrorMessage { get; set; }
        public int StatusCode { get; set; }
    }

    public class ApiResult
    {
        public bool Success { get; set; }
        public string ErrorMessage { get; set; }
        public int StatusCode { get; set; }
    }
}
