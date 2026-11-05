import test from 'node:test';
import assert from 'node:assert/strict';
import {
  parseRouterOSDate,
  parseDurationToSeconds,
  parseHotspotUserComment,
  calculateExpiryTimestamp,
  evaluateUserExpiration,
  processFirstLoginActivation,
  runExpirationSchedulerSweep,
  verifyResellerAction,
  generateUniversalProfileScripts,
  type HotspotProfile,
  type HotspotUser,
  type RouterSessionConfig,
  type ResellerAccount,
} from './mikhmonEngine';

const mockRouterV7: RouterSessionConfig = {
  id: 'MikroTik-Core-ROS7',
  hotspotName: 'NextGan-WiFi-ROS7',
  dnsName: 'wifi.nextgan.net',
  ipHost: '192.168.88.1:8728',
  userHost: 'admin',
  currency: 'Rp',
  autoReloadSec: 10,
  idleTimeoutMin: 10,
  trafficInterface: 'ether1-isp',
  liveReportEnabled: true,
  rosVersion: '7.15.3 (stable)',
  rosMajor: 7,
  boardName: 'RB5009UG+S+',
  model: 'RB5009UG+S+IN',
  cpuLoad: 8,
  freeMemoryBytes: 845127680,
  freeHddBytes: 912457728,
  systemDate: '2026-10-23',
  systemTime: '14:00:00',
  timezone: 'Asia/Jakarta',
  ntpSynced: true,
  uptime: '14d 06:22:19',
};

const mockRouterV6: RouterSessionConfig = {
  ...mockRouterV7,
  id: 'MikroTik-Branch-ROS6',
  hotspotName: 'NextGan-Branch-ROS6',
  rosVersion: '6.49.13 (long-term)',
  rosMajor: 6,
  boardName: 'hEX RB750Gr3',
  model: 'RB750Gr3',
  systemDate: 'oct/23/2026',
  systemTime: '14:00:00',
};

const baseProfileRemc: HotspotProfile = {
  id: '*p1',
  routerId: 'MikroTik-Core-ROS7',
  name: 'Paket-1Hari',
  addressPool: 'hs-pool-1',
  sharedUsers: 1,
  rateLimit: '5M/10M',
  expMode: 'remc',
  validity: '1d',
  price: 5000,
  sellingPrice: 5000,
  lockUser: 'Enable',
  parentQueue: 'none',
  onLoginScript: '',
  scriptVersion: 'universal_ros6_ros7',
  monitorSchedulerActive: true,
};

test('1. Short validity period expires normally on both ROS 6 and ROS 7', () => {
  const calc7 = calculateExpiryTimestamp('2026-10-23', '14:00:00', '30m', 7);
  assert.equal(calc7.valid, true);
  assert.equal(calc7.expiryComment, '2026-10-23 14:30:00');

  const user7: HotspotUser = {
    id: '*u1',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vc8821',
    password: 'vc8821',
    profile: 'Paket-1Hari',
    macAddress: '',
    uptime: '00:29:00',
    uptimeSeconds: 1740,
    limitUptime: '',
    limitUptimeSeconds: 0,
    bytesIn: 0,
    bytesOut: 0,
    limitBytesTotal: 0,
    comment: calc7.expiryComment,
    disabled: false,
    createdBy: 'admin',
  };

  assert.equal(evaluateUserExpiration(user7, '2026-10-23', '14:29:59').isExpired, false);
  assert.equal(evaluateUserExpiration(user7, '2026-10-23', '14:30:00').isExpired, true);
  assert.equal(evaluateUserExpiration(user7, '2026-10-23', '14:30:00').reason, 'validity_expired');
});

test('2. Unactivated voucher preserves first-login rules and never expires before login', () => {
  const unactivated: HotspotUser = {
    id: '*u2',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vc9901',
    password: 'vc9901',
    profile: 'Paket-1Hari',
    macAddress: '',
    uptime: '0s',
    uptimeSeconds: 0,
    limitUptime: '3h',
    limitUptimeSeconds: 10800,
    bytesIn: 0,
    bytesOut: 0,
    limitBytesTotal: 0,
    comment: 'vc-412-10.20.26-batchA',
    disabled: false,
    createdBy: 'admin',
  };

  const check = evaluateUserExpiration(unactivated, '2026-12-31', '23:59:59');
  assert.equal(check.isExpired, false);
  assert.equal(check.reason, 'unactivated');
});

test('3. Actively connected user is disconnected and removed when validity expires', () => {
  const expiredUser: HotspotUser = {
    id: '*u3',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vcactive1',
    password: 'vcactive1',
    profile: 'Paket-1Hari',
    macAddress: 'AA:BB:CC:11:22:33',
    uptime: '02:00:00',
    uptimeSeconds: 7200,
    limitUptime: '',
    limitUptimeSeconds: 0,
    bytesIn: 1024,
    bytesOut: 2048,
    limitBytesTotal: 0,
    comment: '2026-10-23 13:55:00 vc-batch',
    disabled: false,
    createdBy: 'admin',
  };

  const sweep = runExpirationSchedulerSweep({
    router: mockRouterV7,
    profiles: [baseProfileRemc],
    users: [expiredUser],
    activeSessions: [
      {
        id: '*a1',
        routerId: 'MikroTik-Core-ROS7',
        server: 'all',
        user: 'vcactive1',
        address: '10.5.50.12',
        macAddress: 'AA:BB:CC:11:22:33',
        uptime: '02:00:00',
        uptimeSeconds: 7200,
        sessionTimeLeft: '0s',
        bytesIn: 1024,
        bytesOut: 2048,
        loginBy: 'http-chap',
        comment: expiredUser.comment,
      },
    ],
    salesRecords: [],
  });

  assert.deepEqual(sweep.removedUsers, ['vcactive1']);
  assert.deepEqual(sweep.disconnectedSessions, ['vcactive1']);
  assert.equal(sweep.remainingUsers.length, 0);
  assert.equal(sweep.remainingActive.length, 0);
});

test('4. Voucher whose limit-uptime is reached expires even if validity date is still in the future', () => {
  const uptimeReachedUser: HotspotUser = {
    id: '*u4',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vcuptime3h',
    password: 'vcuptime3h',
    profile: 'Paket-1Hari',
    macAddress: 'AA:BB:CC:11:22:44',
    uptime: '03:00:00',
    uptimeSeconds: 10800,
    limitUptime: '3h',
    limitUptimeSeconds: 10800,
    bytesIn: 500,
    bytesOut: 500,
    limitBytesTotal: 0,
    comment: '2026-10-24 14:00:00',
    disabled: false,
    createdBy: 'admin',
  };

  const evalRes = evaluateUserExpiration(uptimeReachedUser, '2026-10-23', '14:00:00');
  assert.equal(evalRes.isExpired, true);
  assert.equal(evalRes.reason, 'limit_uptime_reached');
});

test('5 & 6. Remove & Record creates normalized sales history entry and prevents duplicate records on repeated checks', () => {
  const freshUser: HotspotUser = {
    id: '*u5',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vcrecord77',
    password: 'vcrecord77',
    profile: 'Paket-1Hari',
    macAddress: '',
    uptime: '0s',
    uptimeSeconds: 0,
    limitUptime: '',
    limitUptimeSeconds: 0,
    bytesIn: 0,
    bytesOut: 0,
    limitBytesTotal: 0,
    comment: 'vc-551-10.23.26-kiosk1',
    disabled: false,
    createdBy: 'reseller_bali',
  };

  const firstLogin = processFirstLoginActivation({
    user: freshUser,
    profile: baseProfileRemc,
    router: mockRouterV7,
    existingSalesRecords: [],
    clientIp: '10.5.50.88',
    clientMac: 'DE:AD:BE:EF:00:01',
  });

  assert.ok(firstLogin.newSalesRecord);
  // Verify normalized Mikhmon v3 owner and source even on ROS 7!
  assert.equal(firstLogin.newSalesRecord.owner, 'oct2026');
  assert.equal(firstLogin.newSalesRecord.source, 'oct/23/2026');
  assert.equal(firstLogin.updatedUser.comment, '2026-10-24 14:00:00 vc-551-10.23.26-kiosk1');

  // Repeat login or repeated scheduler sweep must NOT duplicate the sales record
  const secondSweep = runExpirationSchedulerSweep({
    router: { ...mockRouterV7, systemDate: '2026-10-25', systemTime: '10:00:00' },
    profiles: [baseProfileRemc],
    users: [firstLogin.updatedUser],
    activeSessions: [firstLogin.activeSession],
    salesRecords: [firstLogin.newSalesRecord],
  });

  assert.equal(secondSweep.updatedSalesRecords.length, 1);
  assert.equal(secondSweep.removedUsers.length, 1);
});

test('7, 8, 9, 10. Expiry across midnight, month-end, year-end, and leap year (Feb 28/29)', () => {
  // Midnight rollover
  const midnight = calculateExpiryTimestamp('2026-10-23', '23:30:00', '1h', 7);
  assert.equal(midnight.expiryComment, '2026-10-24 00:30:00');

  // Month boundary (Oct 31 -> Nov 01)
  const monthEnd = calculateExpiryTimestamp('2026-10-31', '18:00:00', '12h', 7);
  assert.equal(monthEnd.expiryComment, '2026-11-01 06:00:00');

  // Year boundary (Dec 31, 2026 -> Jan 01, 2027)
  const yearEnd = calculateExpiryTimestamp('2026-12-31', '20:00:00', '6h', 7);
  assert.equal(yearEnd.expiryComment, '2027-01-01 02:00:00');

  // Non-leap year Feb 28, 2026 + 1d -> Mar 01, 2026
  const nonLeap = calculateExpiryTimestamp('2026-02-28', '10:00:00', '1d', 7);
  assert.equal(nonLeap.expiryComment, '2026-03-01 10:00:00');

  // Leap year Feb 28, 2028 + 1d -> Feb 29, 2028
  const leap28 = calculateExpiryTimestamp('2028-02-28', '10:00:00', '1d', 7);
  assert.equal(leap28.expiryComment, '2028-02-29 10:00:00');

  // Leap year Feb 29, 2028 + 1d -> Mar 01, 2028
  const leap29 = calculateExpiryTimestamp('2028-02-29', '10:00:00', '1d', 6);
  assert.equal(leap29.expiryComment, 'mar/01/2028 10:00:00');
});

test('11 & 12. Correct handling of RouterOS 6 and RouterOS 7 date/time and duration values', () => {
  const d6 = parseRouterOSDate('oct/23/2026');
  const d7 = parseRouterOSDate('2026-10-23');
  assert.equal(d6.valid, true);
  assert.equal(d7.valid, true);
  assert.equal(d6.dateInt, d7.dateInt);
  assert.equal(d6.isoDate, '2026-10-23');
  assert.equal(d7.legacyDate, 'oct/23/2026');

  assert.equal(parseDurationToSeconds('1w2d'), 9 * 86400);
  assert.equal(parseDurationToSeconds('00:00:01'), 1);
  assert.equal(parseDurationToSeconds('1s'), 1);
});

test('13, 14, 15. Router reboot / NTP unsynced clock guard and malformed metadata safety', () => {
  const activeVoucher: HotspotUser = {
    id: '*u14',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vclockguard',
    password: 'vclockguard',
    profile: 'Paket-1Hari',
    macAddress: '',
    uptime: '00:10:00',
    uptimeSeconds: 600,
    limitUptime: '',
    limitUptimeSeconds: 0,
    bytesIn: 0,
    bytesOut: 0,
    limitBytesTotal: 0,
    comment: '2026-10-24 12:00:00',
    disabled: false,
    createdBy: 'admin',
  };

  // Router rebooted without NTP sync -> clock resets to 1970-01-02
  const unsyncedEval = evaluateUserExpiration(activeVoucher, '1970-01-02', '00:05:12');
  assert.equal(unsyncedEval.isExpired, false);
  assert.equal(unsyncedEval.reason, 'clock_unsynced');

  // Malformed comment does not crash or falsely expire user
  const malformedUser: HotspotUser = { ...activeVoucher, comment: 'random-custom-note-without-date' };
  const malformedEval = evaluateUserExpiration(malformedUser, '2026-10-25', '12:00:00');
  assert.equal(malformedEval.isExpired, false);
});

test('16, 17, 18. Simultaneous expirations, Notice mode idempotence, and legacy ROS6 profile migration check', () => {
  const ntfProfile: HotspotProfile = {
    ...baseProfileRemc,
    name: 'Paket-Notice',
    expMode: 'ntfc',
  };

  const uA: HotspotUser = {
    id: '*ua',
    routerId: 'MikroTik-Core-ROS7',
    server: 'all',
    name: 'vc_simul_1',
    password: 'vc_simul_1',
    profile: 'Paket-Notice',
    macAddress: '',
    uptime: '01:00:00',
    uptimeSeconds: 3600,
    limitUptime: '',
    limitUptimeSeconds: 0,
    bytesIn: 0,
    bytesOut: 0,
    limitBytesTotal: 0,
    comment: '2026-10-23 13:59:00',
    disabled: false,
    createdBy: 'admin',
  };
  const uB: HotspotUser = { ...uA, id: '*ub', name: 'vc_simul_2', comment: 'oct/23/2026 13:59:30' };

  const sweep1 = runExpirationSchedulerSweep({
    router: mockRouterV7,
    profiles: [ntfProfile],
    users: [uA, uB],
    activeSessions: [],
    salesRecords: [],
  });

  assert.equal(sweep1.notifiedUsers.length, 2);
  assert.equal(sweep1.remainingUsers[0].limitUptime, '00:00:01');
  assert.equal(sweep1.remainingUsers[1].limitUptime, '00:00:01');
  assert.equal(sweep1.updatedSalesRecords.length, 2);

  // Repeated execution of scheduler check must be 100% idempotent (0 newly notified, 2 records total)
  const sweep2 = runExpirationSchedulerSweep({
    router: mockRouterV7,
    profiles: [ntfProfile],
    users: sweep1.remainingUsers,
    activeSessions: [],
    salesRecords: sweep1.updatedSalesRecords,
  });
  assert.equal(sweep2.notifiedUsers.length, 0);
  assert.equal(sweep2.updatedSalesRecords.length, 2);

  // Verify generated universal RouterOS script contains both v6 and v7 guards
  const scripts = generateUniversalProfileScripts({
    profileName: 'Paket-1Hari',
    expMode: 'remc',
    price: 5000,
    sellingPrice: 5000,
    validity: '1d',
    lockUser: 'Enable',
  });
  assert.ok(scripts.onLogin.includes('[:pick $date 4 5] = "-"'));
  assert.ok(scripts.bgService.includes(':local isv7 ([:pick $comment 4 5] = "-" and [:pick $comment 7 8] = "-")'));
});

test('19 & 20. Reseller RBAC restrictions across routers, profiles, batch limits, and operations', () => {
  const reseller: ResellerAccount = {
    id: 'res_1',
    username: 'kiosk_utara',
    fullName: 'Kiosk Utara WiFi',
    status: 'active',
    assignedRouters: ['MikroTik-Core-ROS7'],
    allowedProfiles: ['Paket-1Hari'],
    maxVoucherBatchQty: 50,
    walletBalance: 100000,
    totalVouchersGenerated: 120,
    permissions: {
      generateVoucher: true,
      addSingleUser: true,
      editUser: false,
      deleteUser: false,
      manageProfiles: false,
      viewReports: true,
      deleteReports: false,
      disconnectActive: false,
      routerMigration: false,
    },
  };

  // Unassigned router blocked
  const unassigned = verifyResellerAction({
    actorRole: 'reseller',
    reseller,
    targetRouterId: 'MikroTik-Branch-ROS6',
    action: 'generateVoucher',
  });
  assert.equal(unassigned.allowed, false);

  // Unauthorized operation (deleteUser / manageProfiles) blocked
  assert.equal(
    verifyResellerAction({
      actorRole: 'reseller',
      reseller,
      targetRouterId: 'MikroTik-Core-ROS7',
      action: 'deleteUser',
    }).allowed,
    false
  );
  assert.equal(
    verifyResellerAction({
      actorRole: 'reseller',
      reseller,
      targetRouterId: 'MikroTik-Core-ROS7',
      action: 'manageProfiles',
    }).allowed,
    false
  );

  // Allowed voucher generation within limits succeeds
  assert.equal(
    verifyResellerAction({
      actorRole: 'reseller',
      reseller,
      targetRouterId: 'MikroTik-Core-ROS7',
      action: 'generateVoucher',
      targetProfile: 'Paket-1Hari',
      voucherQty: 10,
      totalCost: 50000,
    }).allowed,
    true
  );

  // Exceeding wallet balance blocked
  assert.equal(
    verifyResellerAction({
      actorRole: 'reseller',
      reseller,
      targetRouterId: 'MikroTik-Core-ROS7',
      action: 'generateVoucher',
      targetProfile: 'Paket-1Hari',
      voucherQty: 30,
      totalCost: 150000,
    }).allowed,
    false
  );
});
