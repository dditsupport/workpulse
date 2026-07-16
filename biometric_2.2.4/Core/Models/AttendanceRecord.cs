using System;
using BiometricAttendance.Core.Interfaces;

namespace BiometricAttendance.Core.Models
{
    public class AttendanceRecord
    {
        public string     EmployeeCode { get; set; }
        public string     DeviceSerial { get; set; }
        public DeviceType DeviceType   { get; set; }
        public int        LocationId   { get; set; }
        public string     PunchType    { get; set; }   // IN | OUT
        public string     PunchMethod  { get; set; }   // fingerprint | otp
        public int        MatchScore   { get; set; }   // normalized 0–100
        public DateTime   Timestamp    { get; set; }
    }

    public class OtpRecord
    {
        public string   EmployeeCode { get; set; }
        public string   Channel      { get; set; }   // email | sms
        public string   MaskedTo     { get; set; }   // masked email or phone
        public int      ExpiresIn    { get; set; }   // minutes
    }
}
