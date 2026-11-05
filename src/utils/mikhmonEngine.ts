/**
 * @license
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Copyright (C) 2018 Laksamadi Guko (Mikhmon v3.20 Upstream)
 * Extended 2026: MikroTik RouterOS 6.x & 7.x Universal Expiration Engine + Reseller RBAC
 */

export type RouterOSMajorVersion = 6 | 7;
export type ExpirationMode = '0' | 'rem' | 'ntf' | 'remc' | 'ntfc';
export type LockUserMode = 'Enable' | 'Disable';
export type VoucherUserMode = 'vc' | 'up';

export interface ParsedRouterOSDate {
  valid: boolean;
  format: 'ros6' | 'ros7' | 'invalid';
  year: number;
  month: number; // 1-12
  day: number; // 1-31
  monthShort: string; // jan..dec
  isoDate: string; // YYYY-MM-DD
  legacyDate: string; // mmm/dd/yyyy
  idbl: string; // mmmyyyy e.g. oct2026
  dateInt: number; // YYYYMMDD e.g. 20261023
}

export interface ParsedExpiryComment {
  isActivatedExpiry: boolean;
  isUnactivatedVoucher: boolean;
  voucherMode: VoucherUserMode | null;
  format: 'ros6' | 'ros7' | 'voucher_tag' | 'plain';
  expiryDateStr: string;
  expiryTimeStr: string;
  isoDateTime: string;
  legacyDateTime: string;
  dateInt: number;
  timeSeconds: number;
  note: string;
  rawComment: string;
}

export interface HotspotProfile {
  id: string;
  routerId: string;
  name: string;
  addressPool: string;
  sharedUsers: number;
  rateLimit: string;
  expMode: ExpirationMode;
  validity: string; // e.g. '1d', '12h', '30d', '5m'
  price: number;
  sellingPrice: number;
  lockUser: LockUserMode;
  parentQueue: string;
  onLoginScript: string;
  scriptVersion: 'legacy_ros6' | 'universal_ros6_ros7' | 'none';
  monitorSchedulerActive: boolean;
}

export interface HotspotUser {
  id: string;
  routerId: string;
  server: string;
  name: string;
  password: string;
  profile: string;
  macAddress: string;
  uptime: string; // e.g. '00:00:00' or '01:15:20'
  uptimeSeconds: number;
  limitUptime: string; // e.g. '', '3h', '1s', '00:00:01'
  limitUptimeSeconds: number;
  bytesIn: number;
  bytesOut: number;
  limitBytesTotal: number;
  comment: string;
  disabled: boolean;
  createdBy: string; // 'admin' or reseller username
}

export interface HotspotActiveSession {
  id: string;
  routerId: string;
  server: string;
  user: string;
  address: string;
  macAddress: string;
  uptime: string;
  uptimeSeconds: number;
  sessionTimeLeft: string;
  bytesIn: number;
  bytesOut: number;
  loginBy: string;
  comment: string;
}

export interface SalesRecordScript {
  id: string;
  routerId: string;
  name: string; // "$rdate-|-$time-|-$user-|-$price-|-$address-|-$mac-|-$validity-|-$profile-|-$comment"
  owner: string; // "$month$year" e.g. "oct2026"
  source: string; // "$rdate" e.g. "oct/23/2026"
  comment: string; // "mikhmon" or "mikhmon:<reseller>"
  dateNormalized: string; // "oct/23/2026"
  isoDate: string; // "2026-10-23"
  time: string;
  username: string;
  price: number;
  address: string;
  mac: string;
  validity: string;
  profile: string;
  userComment: string;
  reseller?: string;
}

export interface RouterScheduler {
  id: string;
  routerId: string;
  name: string;
  startDate: string;
  startTime: string;
  interval: string;
  nextRun: string;
  runCount: number;
  onEvent: string;
  comment: string;
  disabled: boolean;
  scriptVersion: 'legacy_ros6' | 'universal_ros6_ros7' | 'custom';
}

export interface RouterSessionConfig {
  id: string; // session key e.g. 'MikroTik-Core-ROS7'
  hotspotName: string;
  dnsName: string;
  ipHost: string;
  userHost: string;
  currency: string;
  autoReloadSec: number;
  idleTimeoutMin: number;
  trafficInterface: string;
  liveReportEnabled: boolean;
  rosVersion: string; // e.g. '7.15.3 (stable)' or '6.49.13 (long-term)'
  rosMajor: RouterOSMajorVersion;
  boardName: string;
  model: string;
  cpuLoad: number;
  freeMemoryBytes: number;
  freeHddBytes: number;
  systemDate: string; // '2026-10-23' on v7 or 'oct/23/2026' on v6
  systemTime: string; // '14:30:00'
  timezone: string;
  ntpSynced: boolean;
  uptime: string;
}

export interface ResellerPermissions {
  generateVoucher: boolean;
  addSingleUser: boolean;
  editUser: boolean;
  deleteUser: boolean;
  manageProfiles: boolean;
  viewReports: boolean;
  deleteReports: boolean;
  disconnectActive: boolean;
  routerMigration: boolean;
}

export interface ResellerAccount {
  id: string;
  username: string;
  fullName: string;
  passwordPlain?: string;
  status: 'active' | 'suspended';
  assignedRouters: string[]; // router session IDs
  allowedProfiles: string[]; // '*' or specific profile names
  maxVoucherBatchQty: number;
  walletBalance: number;
  totalVouchersGenerated: number;
  permissions: ResellerPermissions;
}

const MONTHS_SHORT = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as const;

export function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

export function getDaysInMonth(year: number, month1To12: number): number {
  if (month1To12 === 2) {
    return isLeapYear(year) ? 29 : 28;
  }
  if ([4, 6, 9, 11].includes(month1To12)) {
    return 30;
  }
  return 31;
}

/**
 * Parses both RouterOS 6 ('oct/23/2026') and RouterOS 7 ('2026-10-23') dates
 * without fragile fixed-offset assumptions.
 */
export function parseRouterOSDate(raw: string): ParsedRouterOSDate {
  const cleaned = (raw || '').trim().toLowerCase();

  // Check RouterOS 7 ISO format: YYYY-MM-DD or YYYY/MM/DD
  const isoMatch = cleaned.match(/^(\d{4})[-/](\d{2})[-/](\d{2})$/);
  if (isoMatch) {
    const year = parseInt(isoMatch[1], 10);
    const month = parseInt(isoMatch[2], 10);
    const day = parseInt(isoMatch[3], 10);
    if (year >= 1970 && month >= 1 && month <= 12 && day >= 1 && day <= getDaysInMonth(year, month)) {
      const monthShort = MONTHS_SHORT[month - 1];
      const mm = String(month).padStart(2, '0');
      const dd = String(day).padStart(2, '0');
      return {
        valid: true,
        format: 'ros7',
        year,
        month,
        day,
        monthShort,
        isoDate: `${year}-${mm}-${dd}`,
        legacyDate: `${monthShort}/${dd}/${year}`,
        idbl: `${monthShort}${year}`,
        dateInt: year * 10000 + month * 100 + day,
      };
    }
  }

  // Check RouterOS 6 format: mmm/dd/yyyy
  const ros6Match = cleaned.match(/^([a-z]{3})\/(\d{2})\/(\d{4})$/);
  if (ros6Match) {
    const monthShort = ros6Match[1];
    const monthIdx = MONTHS_SHORT.indexOf(monthShort as (typeof MONTHS_SHORT)[number]);
    const day = parseInt(ros6Match[2], 10);
    const year = parseInt(ros6Match[3], 10);
    if (monthIdx !== -1 && year >= 1970 && day >= 1 && day <= getDaysInMonth(year, monthIdx + 1)) {
      const month = monthIdx + 1;
      const mm = String(month).padStart(2, '0');
      const dd = String(day).padStart(2, '0');
      return {
        valid: true,
        format: 'ros6',
        year,
        month,
        day,
        monthShort,
        isoDate: `${year}-${mm}-${dd}`,
        legacyDate: `${monthShort}/${dd}/${year}`,
        idbl: `${monthShort}${year}`,
        dateInt: year * 10000 + month * 100 + day,
      };
    }
  }

  return {
    valid: false,
    format: 'invalid',
    year: 0,
    month: 0,
    day: 0,
    monthShort: '',
    isoDate: '',
    legacyDate: '',
    idbl: '',
    dateInt: 0,
  };
}

/**
 * Parses HH:MM:SS into total seconds from midnight (0..86399)
 */
export function parseTimeSeconds(timeStr: string): number {
  const m = (timeStr || '').trim().match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) return -1;
  const hh = parseInt(m[1], 10);
  const mm = parseInt(m[2], 10);
  const ss = m[3] !== undefined ? parseInt(m[3], 10) : 0;
  if (hh < 0 || hh > 23 || mm < 0 || mm > 59 || ss < 0 || ss > 59) return -1;
  return hh * 3600 + mm * 60 + ss;
}

/**
 * Parses MikroTik duration strings across ROS 6 and ROS 7:
 * e.g. '1d', '30d', '12h', '30m', '1w2d', '00:30:00', '1d 02:00:00', '1s', '00:00:01'
 */
export function parseDurationToSeconds(durationStr: string): number {
  const raw = (durationStr || '').trim().toLowerCase();
  if (!raw || raw === '0' || raw === '0s' || raw === 'none') return 0;

  // Pure HH:MM:SS or Dd HH:MM:SS
  const dHmsMatch = raw.match(/^(?:(\d+)w)?\s*(?:(\d+)d)?\s*(\d{1,2}):(\d{2}):(\d{2})$/);
  if (dHmsMatch) {
    const weeks = dHmsMatch[1] ? parseInt(dHmsMatch[1], 10) : 0;
    const days = dHmsMatch[2] ? parseInt(dHmsMatch[2], 10) : 0;
    const hours = parseInt(dHmsMatch[3], 10);
    const mins = parseInt(dHmsMatch[4], 10);
    const secs = parseInt(dHmsMatch[5], 10);
    return ((weeks * 7 + days) * 86400) + hours * 3600 + mins * 60 + secs;
  }

  let total = 0;
  let matchedAny = false;
  const tokenRegex = /(\d+)\s*(w|d|h|m|s)/g;
  let match: RegExpExecArray | null;
  while ((match = tokenRegex.exec(raw)) !== null) {
    matchedAny = true;
    const val = parseInt(match[1], 10);
    const unit = match[2];
    if (unit === 'w') total += val * 7 * 86400;
    else if (unit === 'd') total += val * 86400;
    else if (unit === 'h') total += val * 3600;
    else if (unit === 'm') total += val * 60;
    else if (unit === 's') total += val;
  }

  return matchedAny ? total : 0;
}

/**
 * Checks if a user's limit-uptime value represents Mikhmon's Notice expired marker ('1s' or '00:00:01')
 */
export function isLimitUptimeExpiredMarker(limitUptime: string): boolean {
  const cleaned = (limitUptime || '').trim().toLowerCase();
  return cleaned === '1s' || cleaned === '00:00:01';
}

/**
 * Parses a Hotspot user comment to determine whether it contains an activated expiry timestamp
 * (ROS 6 'mmm/dd/yyyy HH:MM:SS' or ROS 7 'YYYY-MM-DD HH:MM:SS') or an unactivated voucher tag ('vc-...' / 'up-...').
 */
export function parseHotspotUserComment(comment: string): ParsedExpiryComment {
  const rawComment = (comment || '').trim();

  // 1. Check ROS 7 activated expiry comment: YYYY-MM-DD HH:MM:SS [optional note]
  const ros7Match = rawComment.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})(?:\s+(.*))?$/);
  if (ros7Match) {
    const d = parseRouterOSDate(ros7Match[1]);
    const tSec = parseTimeSeconds(ros7Match[2]);
    if (d.valid && tSec >= 0) {
      return {
        isActivatedExpiry: true,
        isUnactivatedVoucher: false,
        voucherMode: null,
        format: 'ros7',
        expiryDateStr: d.isoDate,
        expiryTimeStr: ros7Match[2],
        isoDateTime: `${d.isoDate} ${ros7Match[2]}`,
        legacyDateTime: `${d.legacyDate} ${ros7Match[2]}`,
        dateInt: d.dateInt,
        timeSeconds: tSec,
        note: (ros7Match[3] || '').trim(),
        rawComment,
      };
    }
  }

  // 2. Check ROS 6 activated expiry comment: mmm/dd/yyyy HH:MM:SS [optional note]
  const ros6Match = rawComment.match(/^([a-zA-Z]{3}\/\d{2}\/\d{4})\s+(\d{2}:\d{2}:\d{2})(?:\s+(.*))?$/);
  if (ros6Match) {
    const d = parseRouterOSDate(ros6Match[1]);
    const tSec = parseTimeSeconds(ros6Match[2]);
    if (d.valid && tSec >= 0) {
      return {
        isActivatedExpiry: true,
        isUnactivatedVoucher: false,
        voucherMode: null,
        format: 'ros6',
        expiryDateStr: d.legacyDate,
        expiryTimeStr: ros6Match[2],
        isoDateTime: `${d.isoDate} ${ros6Match[2]}`,
        legacyDateTime: `${d.legacyDate} ${ros6Match[2]}`,
        dateInt: d.dateInt,
        timeSeconds: tSec,
        note: (ros6Match[3] || '').trim(),
        rawComment,
      };
    }
  }

  // 3. Check unactivated voucher comment: vc-... or up-... or empty
  const lower = rawComment.toLowerCase();
  if (lower.startsWith('vc') || lower.startsWith('up') || rawComment === '') {
    const mode: VoucherUserMode | null = lower.startsWith('vc') ? 'vc' : lower.startsWith('up') ? 'up' : null;
    return {
      isActivatedExpiry: false,
      isUnactivatedVoucher: true,
      voucherMode: mode,
      format: 'voucher_tag',
      expiryDateStr: '',
      expiryTimeStr: '',
      isoDateTime: '',
      legacyDateTime: '',
      dateInt: 0,
      timeSeconds: -1,
      note: rawComment,
      rawComment,
    };
  }

  return {
    isActivatedExpiry: false,
    isUnactivatedVoucher: false,
    voucherMode: null,
    format: 'plain',
    expiryDateStr: '',
    expiryTimeStr: '',
    isoDateTime: '',
    legacyDateTime: '',
    dateInt: 0,
    timeSeconds: -1,
    note: rawComment,
    rawComment,
  };
}

/**
 * Calculates the exact expiry date and time from a router clock (date + time) plus a validity string,
 * handling month boundaries, year boundaries, leap days (Feb 29), and midnight rollovers accurately.
 */
export function calculateExpiryTimestamp(
  routerDateStr: string,
  routerTimeStr: string,
  validityStr: string,
  targetRosMajor: RouterOSMajorVersion
): {
  valid: boolean;
  expiryComment: string;
  isoDateTime: string;
  legacyDateTime: string;
  normalizedReportDate: string; // mmm/dd/yyyy for Mikhmon report compatibility
  normalizedReportOwner: string; // mmmyyyy for Mikhmon report compatibility
} {
  const parsedDate = parseRouterOSDate(routerDateStr);
  const startTimeSec = parseTimeSeconds(routerTimeStr);
  const durationSec = parseDurationToSeconds(validityStr);

  if (!parsedDate.valid || startTimeSec < 0 || durationSec <= 0) {
    return {
      valid: false,
      expiryComment: '',
      isoDateTime: '',
      legacyDateTime: '',
      normalizedReportDate: parsedDate.valid ? parsedDate.legacyDate : '',
      normalizedReportOwner: parsedDate.valid ? parsedDate.idbl : '',
    };
  }

  // Use UTC timestamp arithmetic to avoid host local DST glitches while preserving router wall-clock arithmetic
  const [hh, mm, ss] = routerTimeStr.trim().split(':').map((x) => parseInt(x, 10) || 0);
  const startEpochMs = Date.UTC(parsedDate.year, parsedDate.month - 1, parsedDate.day, hh, mm, ss);
  const expiryEpochMs = startEpochMs + durationSec * 1000;
  const expDateObj = new Date(expiryEpochMs);

  const expYear = expDateObj.getUTCFullYear();
  const expMonth = expDateObj.getUTCMonth() + 1;
  const expDay = expDateObj.getUTCDate();
  const expH = String(expDateObj.getUTCHours()).padStart(2, '0');
  const expM = String(expDateObj.getUTCMinutes()).padStart(2, '0');
  const expS = String(expDateObj.getUTCSeconds()).padStart(2, '0');

  const expMonthShort = MONTHS_SHORT[expMonth - 1];
  const expMM = String(expMonth).padStart(2, '0');
  const expDD = String(expDay).padStart(2, '0');

  const isoDateTime = `${expYear}-${expMM}-${expDD} ${expH}:${expM}:${expS}`;
  const legacyDateTime = `${expMonthShort}/${expDD}/${expYear} ${expH}:${expM}:${expS}`;

  return {
    valid: true,
    expiryComment: targetRosMajor === 7 ? isoDateTime : legacyDateTime,
    isoDateTime,
    legacyDateTime,
    normalizedReportDate: parsedDate.legacyDate,
    normalizedReportOwner: parsedDate.idbl,
  };
}

/**
 * Evaluates whether a Hotspot user has expired according to:
 * 1. Validity-based expiry (comment timestamp vs router clock)
 * 2. Limit-uptime expiry (accumulated uptime >= limit-uptime)
 * Includes clock sanity protection: if router year < 2021 (e.g. 1970 NTP unsynced reboot), refuses to false-expire.
 */
export function evaluateUserExpiration(
  user: HotspotUser,
  routerClockDate: string,
  routerClockTime: string
): {
  isExpired: boolean;
  reason: 'validity_expired' | 'limit_uptime_reached' | 'already_marked_expired' | 'not_expired' | 'clock_unsynced' | 'unactivated';
  parsedComment: ParsedExpiryComment;
} {
  const parsedComment = parseHotspotUserComment(user.comment);

  if (isLimitUptimeExpiredMarker(user.limitUptime)) {
    return { isExpired: true, reason: 'already_marked_expired', parsedComment };
  }

  // Check limit-uptime expiry (accumulated online time >= configured limit-uptime)
  const limitSec = parseDurationToSeconds(user.limitUptime);
  const upSec = user.uptimeSeconds > 0 ? user.uptimeSeconds : parseDurationToSeconds(user.uptime);
  if (limitSec > 1 && upSec >= limitSec) {
    return { isExpired: true, reason: 'limit_uptime_reached', parsedComment };
  }

  if (!parsedComment.isActivatedExpiry) {
    return { isExpired: false, reason: 'unactivated', parsedComment };
  }

  const clockDate = parseRouterOSDate(routerClockDate);
  const clockTimeSec = parseTimeSeconds(routerClockTime);
  if (!clockDate.valid || clockDate.year < 2021 || clockTimeSec < 0) {
    // Router clock is unsynced (e.g. 1970-01-02 after reboot without NTP)
    return { isExpired: false, reason: 'clock_unsynced', parsedComment };
  }

  if (
    parsedComment.dateInt < clockDate.dateInt ||
    (parsedComment.dateInt === clockDate.dateInt && parsedComment.timeSeconds <= clockTimeSec)
  ) {
    return { isExpired: true, reason: 'validity_expired', parsedComment };
  }

  return { isExpired: false, reason: 'not_expired', parsedComment };
}

/**
 * Simulates first-login activation on a MikroTik router (v6 or v7),
 * Comparing legacy v3.20 behavior vs Universal Patched behavior.
 */
export function processFirstLoginActivation(params: {
  user: HotspotUser;
  profile: HotspotProfile;
  router: RouterSessionConfig;
  existingSalesRecords: SalesRecordScript[];
  clientIp: string;
  clientMac: string;
}): {
  updatedUser: HotspotUser;
  activeSession: HotspotActiveSession;
  newSalesRecord: SalesRecordScript | null;
  duplicateRecordPrevented: boolean;
  defectTriggeredOnLegacyRos7: string | null;
} {
  const { user, profile, router, existingSalesRecords, clientIp, clientMac } = params;
  const parsedComment = parseHotspotUserComment(user.comment);

  let updatedComment = user.comment;
  let updatedMac = user.macAddress;
  let newSalesRecord: SalesRecordScript | null = null;
  let duplicateRecordPrevented = false;
  let defectTriggeredOnLegacyRos7: string | null = null;

  // Only trigger first-login activation if comment starts with 'vc', 'up', or is empty
  if (parsedComment.isUnactivatedVoucher && profile.expMode !== '0') {
    if (router.rosMajor === 7 && profile.scriptVersion === 'legacy_ros6') {
      // Reproduce authentic upstream Mikhmon v3.20 defect on RouterOS 7:
      // [:pick "2026-10-23" 7 11] => "-23", [:pick "2026-10-23" 0 3] => "202"
      const corruptedYear = router.systemDate.slice(7, 11);
      const corruptedMonth = router.systemDate.slice(0, 3);
      updatedComment = `2026-10/${corruptedYear} ${router.systemTime}`;
      defectTriggeredOnLegacyRos7 = `Legacy ROS6 script sliced ISO date "${router.systemDate}" at fixed offsets [7..11]="${corruptedYear}" and [0..3]="${corruptedMonth}", writing malformed comment "${updatedComment}" that the ROS6 monitor scheduler (checking '/') will never expire!`;
    } else {
      const calc = calculateExpiryTimestamp(router.systemDate, router.systemTime, profile.validity, router.rosMajor);
      if (calc.valid) {
        const noteSuffix = parsedComment.note ? ` ${parsedComment.note}` : '';
        updatedComment = `${calc.expiryComment}${noteSuffix}`;
      }

      if (profile.lockUser === 'Enable') {
        updatedMac = clientMac;
      }

      // If Remove & Record ('remc') or Notice & Record ('ntfc'), create idempotent sales record
      if (profile.expMode === 'remc' || profile.expMode === 'ntfc') {
        const alreadyExists = existingSalesRecords.some(
          (rec) => rec.routerId === router.id && rec.username === user.name
        );
        if (alreadyExists) {
          duplicateRecordPrevented = true;
        } else {
          const rdate = calc.normalizedReportDate || parseRouterOSDate(router.systemDate).legacyDate;
          const rowner = calc.normalizedReportOwner || parseRouterOSDate(router.systemDate).idbl;
          const isoD = parseRouterOSDate(router.systemDate).isoDate;
          const scriptName = `${rdate}-|-${router.systemTime}-|-${user.name}-|-${profile.price}-|-${clientIp}-|-${clientMac}-|-${profile.validity}-|-${profile.name}-|-${user.comment}`;
          newSalesRecord = {
            id: `*rec_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
            routerId: router.id,
            name: scriptName,
            owner: rowner,
            source: rdate,
            comment: user.createdBy && user.createdBy !== 'admin' ? `mikhmon:${user.createdBy}` : 'mikhmon',
            dateNormalized: rdate,
            isoDate: isoD,
            time: router.systemTime,
            username: user.name,
            price: profile.price,
            address: clientIp,
            mac: clientMac,
            validity: profile.validity,
            profile: profile.name,
            userComment: user.comment,
            reseller: user.createdBy,
          };
        }
      }
    }
  }

  const updatedUser: HotspotUser = {
    ...user,
    comment: updatedComment,
    macAddress: updatedMac,
  };

  const activeSession: HotspotActiveSession = {
    id: `*act_${user.name}`,
    routerId: router.id,
    server: user.server || 'all',
    user: user.name,
    address: clientIp,
    macAddress: clientMac,
    uptime: '00:00:01',
    uptimeSeconds: 1,
    sessionTimeLeft: profile.validity || user.limitUptime || 'unlimited',
    bytesIn: 14200,
    bytesOut: 68400,
    loginBy: 'http-chap',
    comment: updatedComment,
  };

  return {
    updatedUser,
    activeSession,
    newSalesRecord,
    duplicateRecordPrevented,
    defectTriggeredOnLegacyRos7,
  };
}

/**
 * Runs the profile monitor scheduler sweep (idempotent) across all users of a router.
 * Handles:
 * - Remove ('rem' / 'remc'): deletes user from Hotspot Users & disconnects active session
 * - Notice ('ntf' / 'ntfc'): sets limit-uptime=1s (or 00:00:01 on ROS7) once & disconnects active session
 * - Limit-uptime reached: disconnects active session
 */
export function runExpirationSchedulerSweep(params: {
  router: RouterSessionConfig;
  profiles: HotspotProfile[];
  users: HotspotUser[];
  activeSessions: HotspotActiveSession[];
  salesRecords: SalesRecordScript[];
}): {
  remainingUsers: HotspotUser[];
  remainingActive: HotspotActiveSession[];
  updatedSalesRecords: SalesRecordScript[];
  removedUsers: string[];
  notifiedUsers: string[];
  disconnectedSessions: string[];
  skippedLegacyRos7Users: string[];
} {
  const { router, profiles, users, activeSessions, salesRecords } = params;
  const profileMap = new Map<string, HotspotProfile>();
  profiles.forEach((p) => profileMap.set(p.name, p));

  const remainingUsers: HotspotUser[] = [];
  const removedUsers: string[] = [];
  const notifiedUsers: string[] = [];
  const disconnectedUsernames = new Set<string>();
  const skippedLegacyRos7Users: string[] = [];
  const updatedSalesRecords = [...salesRecords];

  for (const user of users) {
    const prof = profileMap.get(user.profile);
    if (!prof || prof.expMode === '0') {
      remainingUsers.push(user);
      continue;
    }

    // If profile is still using unpatched legacy_ros6 script on a ROS 7 router,
    // its scheduler only checks `[:pic $comment 3] = "/"` and fails on ISO dates!
    if (router.rosMajor === 7 && prof.scriptVersion === 'legacy_ros6') {
      const parsed = parseHotspotUserComment(user.comment);
      if (parsed.format === 'ros7' || user.comment.includes('-')) {
        skippedLegacyRos7Users.push(user.name);
        remainingUsers.push(user);
        continue;
      }
    }

    const evalResult = evaluateUserExpiration(user, router.systemDate, router.systemTime);

    if (!evalResult.isExpired) {
      remainingUsers.push(user);
      continue;
    }

    // If already marked expired ('1s' or '00:00:01') in Notice mode, do not re-process
    // except ensure no active session remains connected.
    if (evalResult.reason === 'already_marked_expired') {
      disconnectedUsernames.add(user.name);
      remainingUsers.push(user);
      continue;
    }

    // Ensure idempotent sales record exists if mode is remc or ntfc
    if (prof.expMode === 'remc' || prof.expMode === 'ntfc') {
      const hasRecord = updatedSalesRecords.some(
        (r) => r.routerId === router.id && r.username === user.name
      );
      if (!hasRecord) {
        const parsedClock = parseRouterOSDate(router.systemDate);
        const rdate = parsedClock.valid ? parsedClock.legacyDate : 'oct/23/2026';
        const rowner = parsedClock.valid ? parsedClock.idbl : 'oct2026';
        updatedSalesRecords.push({
          id: `*rec_sweep_${user.name}`,
          routerId: router.id,
          name: `${rdate}-|-${router.systemTime}-|-${user.name}-|-${prof.price}-|-0.0.0.0-|-${user.macAddress || '00:00:00:00:00:00'}-|-${prof.validity}-|-${prof.name}-|-${user.comment}`,
          owner: rowner,
          source: rdate,
          comment: user.createdBy && user.createdBy !== 'admin' ? `mikhmon:${user.createdBy}` : 'mikhmon',
          dateNormalized: rdate,
          isoDate: parsedClock.valid ? parsedClock.isoDate : '2026-10-23',
          time: router.systemTime,
          username: user.name,
          price: prof.price,
          address: '0.0.0.0',
          mac: user.macAddress || '00:00:00:00:00:00',
          validity: prof.validity,
          profile: prof.name,
          userComment: user.comment,
          reseller: user.createdBy,
        });
      }
    }

    if (prof.expMode === 'rem' || prof.expMode === 'remc') {
      removedUsers.push(user.name);
      disconnectedUsernames.add(user.name);
      // Do not push to remainingUsers -> removed!
    } else if (prof.expMode === 'ntf' || prof.expMode === 'ntfc') {
      notifiedUsers.push(user.name);
      disconnectedUsernames.add(user.name);
      remainingUsers.push({
        ...user,
        limitUptime: router.rosMajor === 7 ? '00:00:01' : '1s',
        limitUptimeSeconds: 1,
      });
    } else {
      remainingUsers.push(user);
    }
  }

  const remainingActive: HotspotActiveSession[] = [];
  const disconnectedSessions: string[] = [];

  for (const act of activeSessions) {
    if (act.routerId === router.id && disconnectedUsernames.has(act.user)) {
      disconnectedSessions.push(act.user);
    } else {
      remainingActive.push(act);
    }
  }

  return {
    remainingUsers,
    remainingActive,
    updatedSalesRecords,
    removedUsers,
    notifiedUsers,
    disconnectedSessions,
    skippedLegacyRos7Users,
  };
}

/**
 * Generates authentic universal RouterOS 6.x + 7.x scripts for any Hotspot Profile
 */
export function generateUniversalProfileScripts(params: {
  profileName: string;
  expMode: ExpirationMode;
  price: number;
  sellingPrice: number;
  validity: string;
  lockUser: LockUserMode;
}): {
  onLogin: string;
  bgService: string;
  modeCommand: string;
} {
  const { profileName, expMode, price, sellingPrice, validity, lockUser } = params;
  const lockSnippet =
    lockUser === 'Enable'
      ? '; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]'
      : '';

  const recordSnippet = `; :local mac $"mac-address"; :local time [/system clock get time]; :local marray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :local rdate $date; :local rmonth $month; :local ryear $year; :if ([:pick $date 4 5] = "-") do={ :set ryear [:pick $date 0 4]; :local mnum [:tonum [:pick $date 5 7]]; :set rmonth [:pick $marray ($mnum - 1)]; :set rdate ($rmonth . "/" . [:pick $date 8 10] . "/" . $ryear); }; :if ([:len [/system script find where comment="mikhmon" and name~"\\\\-\\\\|\\\\-$user\\\\-\\\\|\\\\-"]] = 0) do={ /system script add name="$rdate-|-$time-|-$user-|-${price}-|-$address-|-$mac-|-${validity}-|-${profileName}-|-$comment" owner="$rmonth$ryear" source="$rdate" comment="mikhmon" }`;

  const onLoginBase = `:put (",${expMode},${price},${validity},${sellingPrice},,${lockUser},"); {:local comment [/ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pic $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local date [/system clock get date]; :local year ""; :local month ""; :if ([:pick $date 4 5] = "-") do={ :set year [:pick $date 0 4]; :local mnum [:tonum [:pick $date 5 7]]; :local marray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :set month [:pick $marray ($mnum - 1)]; } else={ :set year [:pick $date 7 11]; :set month [:pick $date 0 3]; }; /sys sch remove [find where name="$user"]; /sys sch add name="$user" disable=no start-date=$date interval="${validity}"; :delay 2s; :local exp [/sys sch get [/sys sch find where name="$user"] next-run]; :local getxp [:len $exp]; :if ($getxp = 15) do={ :local d [:pic $exp 0 6]; :local t [:pic $exp 7 16]; :local s ("/"); :local exp ("$d$s$year $t"); /ip hotspot user set comment="$exp" [find where name="$user"]; }; :if ($getxp = 8) do={ /ip hotspot user set comment="$date $exp" [find where name="$user"]; }; :if ($getxp = 14) do={ :local d [:pic $exp 0 5]; :local t [:pic $exp 6 14]; /ip hotspot user set comment=("$year-" . $d . " " . $t) [find where name="$user"]; }; :if ($getxp > 15) do={ /ip hotspot user set comment="$exp" [find where name="$user"]; }; /sys sch remove [find where name="$user"]`;

  let onLogin = '';
  let modeCommand = '';

  if (expMode === 'rem') {
    onLogin = `${onLoginBase}${lockSnippet}}}`;
    modeCommand = 'remove';
  } else if (expMode === 'ntf') {
    onLogin = `${onLoginBase}${lockSnippet}}}`;
    modeCommand = 'set limit-uptime=1s';
  } else if (expMode === 'remc') {
    onLogin = `${onLoginBase}${recordSnippet}${lockSnippet}}}`;
    modeCommand = 'remove';
  } else if (expMode === 'ntfc') {
    onLogin = `${onLoginBase}${recordSnippet}${lockSnippet}}}`;
    modeCommand = 'set limit-uptime=1s';
  } else if (expMode === '0' && price > 0) {
    onLogin = `:put (",,${price},,,noexp,${lockUser},")${lockSnippet}`;
  }

  const guardNotAlreadyExpired =
    modeCommand === 'set limit-uptime=1s' ? ' and $limit != "1s" and $limit != "00:00:01"' : '';

  const bgService = `:local dateint do={ :local montharray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :if ([:pick $d 4 5] = "-") do={ :local yr [:pick $d 0 4]; :local mo [:pick $d 5 7]; :local dy [:pick $d 8 10]; :return [:tonum ("$yr$mo$dy")]; } else={ :local days [:pick $d 4 6]; :local month [:pick $d 0 3]; :local year [:pick $d 7 11]; :local monthint ([:find $montharray $month]); :local month ($monthint + 1); :if ([:len $month] = 1) do={ :local zero ("0"); :return [:tonum ("$year$zero$month$days")]; } else={ :return [:tonum ("$year$month$days")]; }; }; }; :local timeint do={ :local hours [:tonum [:pick $t 0 2]]; :local minutes [:tonum [:pick $t 3 5]]; :local seconds [:tonum [:pick $t 6 8]]; :return ($hours * 3600 + $minutes * 60 + $seconds); }; :local date [/system clock get date]; :local time [/system clock get time]; :local today [$dateint d=$date]; :local curtime [$timeint t=$time]; :if ($today > 20200000) do={ :foreach i in=[/ip hotspot user find where profile="${profileName}"] do={ :local comment [/ip hotspot user get $i comment]; :local name [/ip hotspot user get $i name]; :local limit [/ip hotspot user get $i limit-uptime]; :local isv6 ([:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/"); :local isv7 ([:pick $comment 4 5] = "-" and [:pick $comment 7 8] = "-"); :if ($isv6 or $isv7) do={ :local gettime ""; :local expd 0; :if ($isv7) do={ :set gettime [:pick $comment 11 19]; :set expd [$dateint d=[:pick $comment 0 10]]; } else={ :set gettime [:pick $comment 12 20]; :set expd [$dateint d=[:pick $comment 0 11]]; }; :local expt [$timeint t=$gettime]; :if ((($expd < $today) or ($expd = $today and $expt <= $curtime))${guardNotAlreadyExpired}) do={ [ /ip hotspot user ${modeCommand} $i ]; [ /ip hotspot active remove [find where user=$name] ]; [ /ip hotspot cookie remove [find where user=$name] ]; }; }; }; }`;

  return { onLogin, bgService, modeCommand };
}

/**
 * Enforces Reseller RBAC & Router Assignment rules for any action
 */
export function verifyResellerAction(params: {
  actorRole: 'admin' | 'reseller';
  reseller: ResellerAccount | null;
  targetRouterId: string;
  action: keyof ResellerPermissions;
  targetProfile?: string;
  voucherQty?: number;
  totalCost?: number;
}): { allowed: boolean; reason?: string } {
  const { actorRole, reseller, targetRouterId, action, targetProfile, voucherQty, totalCost } = params;
  if (actorRole === 'admin') {
    return { allowed: true };
  }
  if (!reseller) {
    return { allowed: false, reason: 'Reseller session is not authenticated.' };
  }
  if (reseller.status !== 'active') {
    return { allowed: false, reason: `Reseller account "${reseller.username}" is suspended.` };
  }
  if (!reseller.assignedRouters.includes('*') && !reseller.assignedRouters.includes(targetRouterId)) {
    return {
      allowed: false,
      reason: `Router "${targetRouterId}" is not assigned to reseller "${reseller.username}".`,
    };
  }
  if (!reseller.permissions[action]) {
    return {
      allowed: false,
      reason: `Permission "${action}" is disabled for reseller "${reseller.username}".`,
    };
  }
  if (
    targetProfile &&
    !reseller.allowedProfiles.includes('*') &&
    !reseller.allowedProfiles.includes(targetProfile)
  ) {
    return {
      allowed: false,
      reason: `Profile "${targetProfile}" is not permitted for reseller "${reseller.username}".`,
    };
  }
  if (voucherQty !== undefined && voucherQty > reseller.maxVoucherBatchQty) {
    return {
      allowed: false,
      reason: `Requested quantity (${voucherQty}) exceeds max batch limit (${reseller.maxVoucherBatchQty}).`,
    };
  }
  if (totalCost !== undefined && totalCost > reseller.walletBalance) {
    return {
      allowed: false,
      reason: `Insufficient reseller wallet balance (${reseller.walletBalance} < ${totalCost}).`,
    };
  }
  return { allowed: true };
}
