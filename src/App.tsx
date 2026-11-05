/**
 * @license
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Copyright (C) 2018 Laksamadi Guko (Mikhmon v3.20 Upstream)
 * Extended 2026: Universal RouterOS 6.x & 7.x Expiration Engine + Granular Reseller Management
 */

import React, { useMemo, useState } from 'react';
import {
  Activity,
  AlertTriangle,
  Calendar,
  CheckCircle2,
  Clock,
  Cpu,
  Database,
  DollarSign,
  Download,
  FileCode2,
  GitBranch,
  KeyRound,
  Layers,
  Lock,
  LogOut,
  Play,
  Plus,
  Printer,
  RefreshCw,
  Router,
  Search,
  Server,
  Settings,
  ShieldCheck,
  Ticket,
  Trash2,
  Unlock,
  UserCheck,
  UserPlus,
  Users,
  Wifi,
} from 'lucide-react';
import {
  evaluateUserExpiration,
  generateUniversalProfileScripts,
  isLimitUptimeExpiredMarker,
  parseHotspotUserComment,
  parseRouterOSDate,
  processFirstLoginActivation,
  runExpirationSchedulerSweep,
  verifyResellerAction,
  type ExpirationMode,
  type HotspotActiveSession,
  type HotspotProfile,
  type HotspotUser,
  type LockUserMode,
  type ResellerAccount,
  type RouterScheduler,
  type RouterSessionConfig,
  type SalesRecordScript,
} from './utils/mikhmonEngine';
import {
  INITIAL_ACTIVE_SESSIONS,
  INITIAL_PROFILES,
  INITIAL_RESELLERS,
  INITIAL_ROUTERS,
  INITIAL_SALES_RECORDS,
  INITIAL_SCHEDULERS,
  INITIAL_USERS,
  UPSTREAM_REPO_METADATA,
} from './utils/mikhmonSeedData';
import { AuditAndTestMatrixView } from './components/AuditAndTestMatrixView';
import { ResellerManagementView } from './components/ResellerManagementView';

type MikhmonPage =
  | 'dashboard'
  | 'users'
  | 'add-user'
  | 'generate'
  | 'user-profiles'
  | 'add-profile'
  | 'active'
  | 'scheduler'
  | 'selling'
  | 'resellers'
  | 'ros7-audit'
  | 'sessions';

function formatBytes(bytes: number): string {
  if (!bytes || bytes <= 0) return '0 Byte';
  const units = ['Byte', 'KiB', 'MiB', 'GiB', 'TiB'];
  let idx = 0;
  let val = bytes;
  while (val >= 1024 && idx < units.length - 1) {
    val /= 1024;
    idx++;
  }
  return `${val.toFixed(idx === 0 ? 0 : 2)} ${units[idx]}`;
}

export default function App() {
  // Core Router, Profile, User, Active, Scheduler, Sales, and Reseller State
  const [routers, setRouters] = useState<RouterSessionConfig[]>(INITIAL_ROUTERS);
  const [selectedRouterId, setSelectedRouterId] = useState<string>(INITIAL_ROUTERS[0].id);
  const [profiles, setProfiles] = useState<HotspotProfile[]>(INITIAL_PROFILES);
  const [users, setUsers] = useState<HotspotUser[]>(INITIAL_USERS);
  const [activeSessions, setActiveSessions] = useState<HotspotActiveSession[]>(
    INITIAL_ACTIVE_SESSIONS
  );
  const [schedulers, setSchedulers] = useState<RouterScheduler[]>(INITIAL_SCHEDULERS);
  const [salesRecords, setSalesRecords] = useState<SalesRecordScript[]>(INITIAL_SALES_RECORDS);
  const [resellers, setResellers] = useState<ResellerAccount[]>(INITIAL_RESELLERS);

  // Active Actor / Session Role ('admin' or 'reseller')
  const [actorRole, setActorRole] = useState<'admin' | 'reseller'>('admin');
  const [activeResellerId, setActiveResellerId] = useState<string | null>(null);

  // Navigation & Filter State
  const [currentPage, setCurrentPage] = useState<MikhmonPage>('dashboard');
  const [userFilterProfile, setUserFilterProfile] = useState<string>('all');
  const [userSearchQuery, setUserSearchQuery] = useState<string>('');
  const [userExpiredOnly, setUserExpiredOnly] = useState<boolean>(false);
  const [reportFilterMonth, setReportFilterMonth] = useState<string>('oct2026');
  const [reportSearchPrefix, setReportSearchPrefix] = useState<string>('');
  const [statusMessage, setStatusMessage] = useState<{
    type: 'success' | 'warning' | 'error';
    text: string;
  } | null>(null);

  // Voucher Print Modal State
  const [printVoucherBatch, setPrintVoucherBatch] = useState<HotspotUser[] | null>(null);

  // Generate Voucher Form State
  const [genQty, setGenQty] = useState<number>(5);
  const [genMode, setGenMode] = useState<'vc' | 'up'>('vc');
  const [genLength, setGenLength] = useState<number>(6);
  const [genPrefix, setGenPrefix] = useState<string>('vc');
  const [genProfile, setGenProfile] = useState<string>('Voucher-1Hari');
  const [genTimeLimit, setGenTimeLimit] = useState<string>('');
  const [genComment, setGenComment] = useState<string>('kiosk-batch');

  // Add Single User Form State
  const [addUName, setAddUName] = useState<string>('');
  const [addUPass, setAddUPass] = useState<string>('');
  const [addUProfile, setAddUProfile] = useState<string>('Voucher-1Hari');
  const [addUTimeLimit, setAddUTimeLimit] = useState<string>('');
  const [addUComment, setAddUComment] = useState<string>('');

  // Add / Edit Profile Form State
  const [profName, setProfName] = useState<string>('');
  const [profPool, setProfPool] = useState<string>('hs-pool-core');
  const [profShared, setProfShared] = useState<string>('1');
  const [profRate, setProfRate] = useState<string>('5M/10M');
  const [profExpMode, setProfExpMode] = useState<ExpirationMode>('remc');
  const [profValidity, setProfValidity] = useState<string>('1d');
  const [profPrice, setProfPrice] = useState<string>('5000');
  const [profSPrice, setProfSPrice] = useState<string>('5000');
  const [profLock, setProfLock] = useState<LockUserMode>('Enable');

  const activeReseller = useMemo(
    () => resellers.find((r) => r.id === activeResellerId) || null,
    [resellers, activeResellerId]
  );

  const currentRouter = useMemo(
    () => routers.find((r) => r.id === selectedRouterId) || routers[0],
    [routers, selectedRouterId]
  );

  // Filter data for the currently active MikroTik Router Session
  const routerProfiles = useMemo(
    () => profiles.filter((p) => p.routerId === currentRouter.id),
    [profiles, currentRouter.id]
  );

  const routerUsers = useMemo(() => {
    return users.filter((u) => {
      if (u.routerId !== currentRouter.id) return false;
      if (actorRole === 'reseller' && activeReseller && u.createdBy !== activeReseller.username) {
        return false;
      }
      if (userFilterProfile !== 'all' && u.profile !== userFilterProfile) return false;
      if (userExpiredOnly && !isLimitUptimeExpiredMarker(u.limitUptime)) {
        const ev = evaluateUserExpiration(u, currentRouter.systemDate, currentRouter.systemTime);
        if (!ev.isExpired) return false;
      }
      if (userSearchQuery.trim() !== '') {
        const q = userSearchQuery.toLowerCase();
        return (
          u.name.toLowerCase().includes(q) ||
          u.comment.toLowerCase().includes(q) ||
          u.profile.toLowerCase().includes(q)
        );
      }
      return true;
    });
  }, [
    users,
    currentRouter,
    actorRole,
    activeReseller,
    userFilterProfile,
    userExpiredOnly,
    userSearchQuery,
  ]);

  const routerActiveSessions = useMemo(
    () => activeSessions.filter((a) => a.routerId === currentRouter.id),
    [activeSessions, currentRouter.id]
  );

  const routerSchedulers = useMemo(
    () => schedulers.filter((s) => s.routerId === currentRouter.id),
    [schedulers, currentRouter.id]
  );

  const routerSalesRecords = useMemo(() => {
    return salesRecords.filter((sr) => {
      if (sr.routerId !== currentRouter.id) return false;
      if (actorRole === 'reseller' && activeReseller && sr.reseller !== activeReseller.username) {
        return false;
      }
      if (reportFilterMonth !== 'all' && sr.owner !== reportFilterMonth) return false;
      if (
        reportSearchPrefix.trim() !== '' &&
        !sr.username.toLowerCase().startsWith(reportSearchPrefix.trim().toLowerCase())
      ) {
        return false;
      }
      return true;
    });
  }, [salesRecords, currentRouter.id, actorRole, activeReseller, reportFilterMonth, reportSearchPrefix]);

  // Live Report Today & Month Calculations (Normalized across ROS6 & ROS7)
  const liveReportSummary = useMemo(() => {
    const parsedClock = parseRouterOSDate(currentRouter.systemDate);
    const todayLegacy = parsedClock.valid ? parsedClock.legacyDate : 'oct/23/2026';
    const monthOwner = parsedClock.valid ? parsedClock.idbl : 'oct2026';

    let todayCount = 0;
    let todayIncome = 0;
    let monthCount = 0;
    let monthIncome = 0;

    salesRecords
      .filter((sr) => sr.routerId === currentRouter.id)
      .forEach((sr) => {
        if (sr.owner === monthOwner) {
          monthCount++;
          monthIncome += sr.price;
        }
        if (sr.dateNormalized === todayLegacy || sr.isoDate === parsedClock.isoDate) {
          todayCount++;
          todayIncome += sr.price;
        }
      });

    return { todayCount, todayIncome, monthCount, monthIncome };
  }, [salesRecords, currentRouter]);

  // Switch Router Session with Reseller RBAC enforcement
  const handleSelectRouterSession = (routerId: string) => {
    if (actorRole === 'reseller' && activeReseller) {
      if (
        !activeReseller.assignedRouters.includes('*') &&
        !activeReseller.assignedRouters.includes(routerId)
      ) {
        setStatusMessage({
          type: 'error',
          text: `RBAC Security Block: Reseller "${activeReseller.username}" is not assigned to router session "${routerId}".`,
        });
        return;
      }
    }
    setSelectedRouterId(routerId);
    setStatusMessage({
      type: 'success',
      text: `Switched active MikroTik session to ${routerId} (RouterOS ${
        routers.find((r) => r.id === routerId)?.rosVersion
      }).`,
    });
  };

  // Switch Actor Role (Admin vs Reseller)
  const handleSelectActor = (role: 'admin' | 'reseller', resellerId: string | null) => {
    setActorRole(role);
    setActiveResellerId(resellerId);
    if (role === 'reseller' && resellerId) {
      const targetReseller = resellers.find((r) => r.id === resellerId);
      if (targetReseller && targetReseller.assignedRouters.length > 0) {
        const firstAssigned = targetReseller.assignedRouters[0];
        if (firstAssigned !== '*') {
          setSelectedRouterId(firstAssigned);
        }
      }
      setStatusMessage({
        type: 'warning',
        text: `Active Session switched to Reseller "${targetReseller?.username}". Unassigned routers and unauthorized actions are now restricted.`,
      });
    } else {
      setStatusMessage({
        type: 'success',
        text: 'Active Session switched to Super Administrator (mikhmon). Full router and profile privileges enabled.',
      });
    }
  };

  // Advance Router Clock & Run Universal Expiration Scheduler Sweep
  const handleRunSchedulerSweep = (advanceHours = 0) => {
    let targetRouter = currentRouter;
    if (advanceHours > 0) {
      const parsed = parseRouterOSDate(currentRouter.systemDate);
      const [hh, mm, ss] = currentRouter.systemTime.split(':').map((n) => parseInt(n, 10) || 0);
      const nextEpoch =
        Date.UTC(parsed.year, parsed.month - 1, parsed.day, hh, mm, ss) +
        advanceHours * 3600 * 1000;
      const dt = new Date(nextEpoch);
      const y = dt.getUTCFullYear();
      const m = dt.getUTCMonth() + 1;
      const d = dt.getUTCDate();
      const months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
      const newDateStr =
        currentRouter.rosMajor === 7
          ? `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
          : `${months[m - 1]}/${String(d).padStart(2, '0')}/${y}`;
      const newTimeStr = `${String(dt.getUTCHours()).padStart(2, '0')}:${String(
        dt.getUTCMinutes()
      ).padStart(2, '0')}:${String(dt.getUTCSeconds()).padStart(2, '0')}`;

      targetRouter = {
        ...currentRouter,
        systemDate: newDateStr,
        systemTime: newTimeStr,
      };
      setRouters((prev) => prev.map((r) => (r.id === currentRouter.id ? targetRouter : r)));
    }

    const thisRouterUsers = users.filter((u) => u.routerId === targetRouter.id);
    const otherRouterUsers = users.filter((u) => u.routerId !== targetRouter.id);

    const sweep = runExpirationSchedulerSweep({
      router: targetRouter,
      profiles: routerProfiles,
      users: thisRouterUsers,
      activeSessions,
      salesRecords,
    });

    setUsers([...otherRouterUsers, ...sweep.remainingUsers]);
    setActiveSessions(sweep.remainingActive);
    setSalesRecords(sweep.updatedSalesRecords);
    setSchedulers((prev) =>
      prev.map((s) =>
        s.routerId === targetRouter.id ? { ...s, runCount: s.runCount + 1 } : s
      )
    );

    const summaryParts: string[] = [];
    if (sweep.removedUsers.length > 0) {
      summaryParts.push(`Removed ${sweep.removedUsers.length} expired user(s) [${sweep.removedUsers.join(', ')}]`);
    }
    if (sweep.notifiedUsers.length > 0) {
      summaryParts.push(
        `Marked ${sweep.notifiedUsers.length} Notice user(s) limit-uptime=${
          targetRouter.rosMajor === 7 ? '00:00:01' : '1s'
        } [${sweep.notifiedUsers.join(', ')}]`
      );
    }
    if (sweep.disconnectedSessions.length > 0) {
      summaryParts.push(
        `Disconnected ${sweep.disconnectedSessions.length} active session(s) [${sweep.disconnectedSessions.join(', ')}]`
      );
    }
    if (sweep.skippedLegacyRos7Users.length > 0) {
      summaryParts.push(
        `Skipped ${sweep.skippedLegacyRos7Users.length} user(s) on unmigrated Legacy ROS6 profile`
      );
    }

    setStatusMessage({
      type: 'success',
      text:
        summaryParts.length > 0
          ? `Scheduler Monitor Sweep (${targetRouter.systemDate} ${targetRouter.systemTime}): ${summaryParts.join(' · ')}.`
          : `Scheduler Monitor Sweep (${targetRouter.systemDate} ${targetRouter.systemTime}): Checked all profiles idempotently — 0 newly expired vouchers.`,
    });
  };

  // Simulate First-Login Activation on any unactivated voucher
  const handleSimulateFirstLogin = (user: HotspotUser) => {
    const prof = routerProfiles.find((p) => p.name === user.profile) || routerProfiles[0];
    if (!prof) return;

    const result = processFirstLoginActivation({
      user,
      profile: prof,
      router: currentRouter,
      existingSalesRecords: salesRecords,
      clientIp: `10.5.50.${Math.floor(Math.random() * 200) + 10}`,
      clientMac: `B8:27:EB:${Math.floor(Math.random() * 89 + 10)}:${Math.floor(
        Math.random() * 89 + 10
      )}:A1`,
    });

    setUsers((prev) => prev.map((u) => (u.id === user.id ? result.updatedUser : u)));
    setActiveSessions((prev) => [
      ...prev.filter((a) => !(a.routerId === currentRouter.id && a.user === user.name)),
      result.activeSession,
    ]);
    if (result.newSalesRecord) {
      setSalesRecords((prev) => [result.newSalesRecord!, ...prev]);
    }

    if (result.defectTriggeredOnLegacyRos7) {
      setStatusMessage({
        type: 'error',
        text: `Unmigrated Legacy Profile Warning on ROS 7: ${result.defectTriggeredOnLegacyRos7}`,
      });
    } else {
      setStatusMessage({
        type: 'success',
        text: `First-login activated "${user.name}" on ROS ${currentRouter.rosMajor}: stamped comment "${result.updatedUser.comment}"${
          result.newSalesRecord
            ? ` and created idempotent sales record (owner="${result.newSalesRecord.owner}").`
            : '.'
        }`,
      });
    }
  };

  // Migrate Legacy ROS6 Profiles on Router to Universal ROS6/ROS7
  const handleMigrateRouterProfiles = (routerId: string): number => {
    const permCheck = verifyResellerAction({
      actorRole,
      reseller: activeReseller,
      targetRouterId: routerId,
      action: 'routerMigration',
    });
    if (!permCheck.allowed) {
      setStatusMessage({ type: 'error', text: permCheck.reason || 'Migration not allowed.' });
      return 0;
    }

    let migratedCount = 0;
    setProfiles((prev) =>
      prev.map((p) => {
        if (p.routerId === routerId && p.scriptVersion === 'legacy_ros6') {
          migratedCount++;
          const universal = generateUniversalProfileScripts({
            profileName: p.name,
            expMode: p.expMode,
            price: p.price,
            sellingPrice: p.sellingPrice,
            validity: p.validity,
            lockUser: p.lockUser,
          });
          return {
            ...p,
            onLoginScript: universal.onLogin,
            scriptVersion: 'universal_ros6_ros7',
            monitorSchedulerActive: true,
          };
        }
        return p;
      })
    );

    setSchedulers((prev) =>
      prev.map((s) =>
        s.routerId === routerId && s.scriptVersion === 'legacy_ros6'
          ? {
              ...s,
              scriptVersion: 'universal_ros6_ros7',
              comment: `Monitor Profile ${s.name} (Universal ROS6/ROS7)`,
            }
          : s
      )
    );

    setStatusMessage({
      type: 'success',
      text:
        migratedCount > 0
          ? `Migrated ${migratedCount} profile(s) and monitor scheduler(s) on ${routerId} to Universal ROS 6 & ROS 7 scripts.`
          : `All profiles on ${routerId} are already running Universal ROS 6 & ROS 7 scripts.`,
    });

    return migratedCount;
  };

  // Generate Batch Vouchers Handler (with Reseller RBAC & Wallet deduction)
  const handleGenerateVouchers = (e: React.FormEvent) => {
    e.preventDefault();
    const prof = routerProfiles.find((p) => p.name === genProfile) || routerProfiles[0];
    if (!prof) return;

    const totalCost = prof.price * genQty;
    const permCheck = verifyResellerAction({
      actorRole,
      reseller: activeReseller,
      targetRouterId: currentRouter.id,
      action: 'generateVoucher',
      targetProfile: prof.name,
      voucherQty: genQty,
      totalCost,
    });

    if (!permCheck.allowed) {
      setStatusMessage({
        type: 'error',
        text: `Reseller Restriction: ${permCheck.reason}`,
      });
      return;
    }

    const batchCode = Math.floor(Math.random() * 899 + 100);
    const cleanNote = (genComment || 'batch').replace(/[^a-zA-Z0-9_-]/g, '');
    const commentTag = `${genMode}-${batchCode}-10.23.26-${cleanNote}`;
    const chars = '23456789abcdefghijkmnprstuvwxyz';
    const createdBatch: HotspotUser[] = [];

    for (let i = 0; i < genQty; i++) {
      let randCode = '';
      for (let c = 0; c < genLength; c++) {
        randCode += chars[Math.floor(Math.random() * chars.length)];
      }
      const uname = `${genPrefix}${randCode}`;
      const upass = genMode === 'vc' ? uname : `${Math.floor(Math.random() * 8999 + 1000)}`;
      createdBatch.push({
        id: `*gen_${Date.now()}_${i}`,
        routerId: currentRouter.id,
        server: 'all',
        name: uname,
        password: upass,
        profile: prof.name,
        macAddress: '',
        uptime: '0s',
        uptimeSeconds: 0,
        limitUptime: genTimeLimit.trim(),
        limitUptimeSeconds: 0,
        bytesIn: 0,
        bytesOut: 0,
        limitBytesTotal: 0,
        comment: commentTag,
        disabled: false,
        createdBy: actorRole === 'reseller' && activeReseller ? activeReseller.username : 'admin',
      });
    }

    setUsers((prev) => [...createdBatch, ...prev]);

    if (actorRole === 'reseller' && activeReseller) {
      setResellers((prev) =>
        prev.map((r) =>
          r.id === activeReseller.id
            ? {
                ...r,
                walletBalance: r.walletBalance - totalCost,
                totalVouchersGenerated: r.totalVouchersGenerated + genQty,
              }
            : r
        )
      );
    }

    setStatusMessage({
      type: 'success',
      text: `Generated ${genQty} voucher(s) with profile "${prof.name}" and comment "${commentTag}". Ready for first-login activation.`,
    });
    setCurrentPage('users');
  };

  // Add Single Hotspot User
  const handleAddSingleUser = (e: React.FormEvent) => {
    e.preventDefault();
    const permCheck = verifyResellerAction({
      actorRole,
      reseller: activeReseller,
      targetRouterId: currentRouter.id,
      action: 'addSingleUser',
      targetProfile: addUProfile,
    });
    if (!permCheck.allowed) {
      setStatusMessage({ type: 'error', text: `Reseller Restriction: ${permCheck.reason}` });
      return;
    }
    const cleanName = addUName.trim();
    const cleanPass = addUPass.trim() || cleanName;
    if (!cleanName) {
      setStatusMessage({ type: 'error', text: 'Please enter a username.' });
      return;
    }
    const prefixMode = cleanName === cleanPass ? 'vc-' : 'up-';
    const newUser: HotspotUser = {
      id: `*u_${Date.now()}`,
      routerId: currentRouter.id,
      server: 'all',
      name: cleanName,
      password: cleanPass,
      profile: addUProfile,
      macAddress: '',
      uptime: '0s',
      uptimeSeconds: 0,
      limitUptime: addUTimeLimit.trim(),
      limitUptimeSeconds: 0,
      bytesIn: 0,
      bytesOut: 0,
      limitBytesTotal: 0,
      comment: `${prefixMode}${addUComment.trim() || 'manual'}`,
      disabled: false,
      createdBy: actorRole === 'reseller' && activeReseller ? activeReseller.username : 'admin',
    };
    setUsers((prev) => [newUser, ...prev]);
    setAddUName('');
    setAddUPass('');
    setAddUComment('');
    setStatusMessage({
      type: 'success',
      text: `Added Hotspot user "${cleanName}" (${newUser.comment}) on ${currentRouter.id}.`,
    });
    setCurrentPage('users');
  };

  // Add New Hotspot Profile (with Universal ROS6/ROS7 script generation)
  const handleAddProfile = (e: React.FormEvent) => {
    e.preventDefault();
    const permCheck = verifyResellerAction({
      actorRole,
      reseller: activeReseller,
      targetRouterId: currentRouter.id,
      action: 'manageProfiles',
    });
    if (!permCheck.allowed) {
      setStatusMessage({ type: 'error', text: `Reseller Restriction: ${permCheck.reason}` });
      return;
    }
    const formattedName = profName.trim().replace(/\s+/g, '-');
    if (!formattedName) {
      setStatusMessage({ type: 'error', text: 'Profile name is required.' });
      return;
    }

    const priceNum = parseFloat(profPrice) || 0;
    const sPriceNum = parseFloat(profSPrice) || priceNum;
    const scripts = generateUniversalProfileScripts({
      profileName: formattedName,
      expMode: profExpMode,
      price: priceNum,
      sellingPrice: sPriceNum,
      validity: profValidity.trim() || '1d',
      lockUser: profLock,
    });

    const newProf: HotspotProfile = {
      id: `*prof_${Date.now()}`,
      routerId: currentRouter.id,
      name: formattedName,
      addressPool: profPool,
      sharedUsers: parseInt(profShared, 10) || 1,
      rateLimit: profRate.trim() || '5M/10M',
      expMode: profExpMode,
      validity: profExpMode === '0' ? '' : profValidity.trim() || '1d',
      price: priceNum,
      sellingPrice: sPriceNum,
      lockUser: profLock,
      parentQueue: 'none',
      onLoginScript: scripts.onLogin,
      scriptVersion: profExpMode === '0' ? 'none' : 'universal_ros6_ros7',
      monitorSchedulerActive: profExpMode !== '0',
    };

    setProfiles((prev) => [...prev, newProf]);

    if (profExpMode !== '0') {
      setSchedulers((prev) => [
        ...prev,
        {
          id: `*sch_${Date.now()}`,
          routerId: currentRouter.id,
          name: formattedName,
          startDate: currentRouter.systemDate,
          startTime: '01:25:10',
          interval: '00:02:30',
          nextRun: `${currentRouter.systemDate} 14:32:30`,
          runCount: 1,
          onEvent: scripts.bgService,
          comment: `Monitor Profile ${formattedName}`,
          disabled: false,
          scriptVersion: 'universal_ros6_ros7',
        },
      ]);
    }

    setProfName('');
    setStatusMessage({
      type: 'success',
      text: `Created Hotspot Profile "${formattedName}" with Universal ROS 6 & ROS 7 on-login and monitor scheduler scripts.`,
    });
    setCurrentPage('user-profiles');
  };

  // Delete Hotspot User (with Reseller RBAC enforcement)
  const handleDeleteUser = (user: HotspotUser) => {
    const permCheck = verifyResellerAction({
      actorRole,
      reseller: activeReseller,
      targetRouterId: currentRouter.id,
      action: 'deleteUser',
    });
    if (!permCheck.allowed) {
      setStatusMessage({ type: 'error', text: `Reseller Restriction: ${permCheck.reason}` });
      return;
    }
    setUsers((prev) => prev.filter((u) => u.id !== user.id));
    setActiveSessions((prev) =>
      prev.filter((a) => !(a.routerId === currentRouter.id && a.user === user.name))
    );
    setStatusMessage({
      type: 'success',
      text: `Removed Hotspot user "${user.name}" from ${currentRouter.id}.`,
    });
  };

  // Remove All Expired Users (Supports both ROS6 '1s' and ROS7 '00:00:01' + expired validity comments)
  const handleRemoveAllExpiredUsers = () => {
    const permCheck = verifyResellerAction({
      actorRole,
      reseller: activeReseller,
      targetRouterId: currentRouter.id,
      action: 'deleteUser',
    });
    if (!permCheck.allowed) {
      setStatusMessage({ type: 'error', text: `Reseller Restriction: ${permCheck.reason}` });
      return;
    }

    let removedCount = 0;
    setUsers((prev) =>
      prev.filter((u) => {
        if (u.routerId !== currentRouter.id) return true;
        const ev = evaluateUserExpiration(u, currentRouter.systemDate, currentRouter.systemTime);
        if (ev.isExpired || isLimitUptimeExpiredMarker(u.limitUptime)) {
          removedCount++;
          return false;
        }
        return true;
      })
    );
    setStatusMessage({
      type: 'success',
      text: `Removed ${removedCount} expired Hotspot user(s) (including ROS 6 "1s" and ROS 7 "00:00:01" markers) on ${currentRouter.id}.`,
    });
  };

  return (
    <div className="min-h-screen bg-[#1e2125] text-[#e4e7ea] font-['Public_Sans',sans-serif] flex flex-col">
      {/* Authentic Mikhmon v3 Top Navbar */}
      <header className="h-12 bg-[#272b30] border-b border-[#3a4149] px-4 flex items-center justify-between gap-4 shrink-0">
        <div className="flex items-center gap-4 min-w-0">
          <button
            type="button"
            onClick={() => setCurrentPage('dashboard')}
            className="text-base font-bold tracking-wider text-white font-['Plus_Jakarta_Sans',sans-serif] whitespace-nowrap shrink-0 flex items-center gap-2"
          >
            <Wifi className="w-4 h-4 text-[#20a8d8]" />
            <span>MIKHMON v3.20</span>
          </button>
          <span className="text-slate-500 hidden sm:inline">|</span>
          <span className="text-xs font-medium text-slate-300 truncate hidden sm:inline">
            {currentRouter.hotspotName} ({currentRouter.ipHost}) · RouterOS {currentRouter.rosVersion}
          </span>
        </div>

        {/* Right Controls: Actor Selector, Router Session Selector, Clock & Audit Shortcut */}
        <div className="flex items-center gap-2 shrink-0">
          <select
            aria-label="Operator Role Session"
            value={actorRole === 'admin' ? 'admin' : activeResellerId || 'admin'}
            onChange={(e) => {
              const val = e.target.value;
              if (val === 'admin') handleSelectActor('admin', null);
              else handleSelectActor('reseller', val);
            }}
            className="bg-[#1e2125] border border-[#3a4149] text-xs text-white rounded px-2.5 py-1 font-medium"
          >
            <option value="admin">Role: Super Admin (mikhmon)</option>
            {resellers.map((r) => (
              <option key={r.id} value={r.id}>
                Role: Reseller ({r.username} - Rp {r.walletBalance.toLocaleString('id-ID')})
              </option>
            ))}
          </select>

          <select
            aria-label="MikroTik Router Session"
            value={selectedRouterId}
            onChange={(e) => handleSelectRouterSession(e.target.value)}
            className="bg-[#1e2125] border border-[#3a4149] text-xs text-cyan-300 rounded px-2.5 py-1 font-mono"
          >
            {routers.map((r) => (
              <option key={r.id} value={r.id}>
                {r.id} [ROS v{r.rosMajor}]
              </option>
            ))}
          </select>

          <button
            type="button"
            onClick={() => setCurrentPage('ros7-audit')}
            className="px-3 py-1 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-xs font-semibold rounded transition-colors whitespace-nowrap"
          >
            ROS6/ROS7 Test Matrix
          </button>
        </div>
      </header>

      <div className="flex flex-1 min-h-0">
        {/* Authentic Mikhmon v3 Left Sidenav */}
        <aside className="w-60 bg-[#272b30] border-r border-[#3a4149] flex flex-col justify-between shrink-0">
          <div className="p-3 space-y-1">
            <div className="px-3 py-2 mb-2 bg-[#1e2125] border border-[#3a4149] rounded">
              <div className="text-xs font-bold text-white truncate">{currentRouter.hotspotName}</div>
              <div className="text-[11px] font-mono text-slate-400 tabular-nums mt-0.5">
                Clock: {currentRouter.systemDate} {currentRouter.systemTime}
              </div>
              <div className="text-[11px] text-emerald-400 font-mono mt-0.5">
                {actorRole === 'admin'
                  ? 'Operator: Admin (Full Access)'
                  : `Reseller: ${activeReseller?.username}`}
              </div>
            </div>

            <button
              type="button"
              onClick={() => setCurrentPage('dashboard')}
              className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'dashboard'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <Activity className="w-4 h-4" />
              <span>Dashboard</span>
            </button>

            <div className="pt-2 pb-1 px-3 text-[11px] font-semibold text-slate-400">
              Hotspot Management
            </div>

            <button
              type="button"
              onClick={() => setCurrentPage('users')}
              className={`w-full flex items-center justify-between px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'users'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <span className="flex items-center gap-2.5">
                <Users className="w-4 h-4" />
                <span>Hotspot Users</span>
              </span>
              <span className="font-mono text-[11px] tabular-nums">{routerUsers.length}</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('generate')}
              className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'generate'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <Ticket className="w-4 h-4" />
              <span>Generate Vouchers</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('add-user')}
              className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'add-user'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <UserPlus className="w-4 h-4" />
              <span>Add Single User</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('user-profiles')}
              className={`w-full flex items-center justify-between px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'user-profiles' || currentPage === 'add-profile'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <span className="flex items-center gap-2.5">
                <Layers className="w-4 h-4" />
                <span>User Profiles</span>
              </span>
              <span className="font-mono text-[11px] tabular-nums">{routerProfiles.length}</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('active')}
              className={`w-full flex items-center justify-between px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'active'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <span className="flex items-center gap-2.5">
                <Wifi className="w-4 h-4" />
                <span>Hotspot Active</span>
              </span>
              <span className="font-mono text-[11px] tabular-nums">
                {routerActiveSessions.length}
              </span>
            </button>

            <div className="pt-2 pb-1 px-3 text-[11px] font-semibold text-slate-400">
              Automation, Reports & RBAC
            </div>

            <button
              type="button"
              onClick={() => setCurrentPage('scheduler')}
              className={`w-full flex items-center justify-between px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'scheduler'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <span className="flex items-center gap-2.5">
                <Clock className="w-4 h-4" />
                <span>System Scheduler</span>
              </span>
              <span className="font-mono text-[11px] tabular-nums">{routerSchedulers.length}</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('selling')}
              className={`w-full flex items-center justify-between px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'selling'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <span className="flex items-center gap-2.5">
                <DollarSign className="w-4 h-4" />
                <span>Selling Report</span>
              </span>
              <span className="font-mono text-[11px] tabular-nums">
                {routerSalesRecords.length}
              </span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('resellers')}
              className={`w-full flex items-center justify-between px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'resellers'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <span className="flex items-center gap-2.5">
                <ShieldCheck className="w-4 h-4" />
                <span>Reseller Management</span>
              </span>
              <span className="font-mono text-[11px] tabular-nums">{resellers.length}</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('ros7-audit')}
              className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'ros7-audit'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <GitBranch className="w-4 h-4" />
              <span>ROS6/ROS7 Expiry & Git Audit</span>
            </button>

            <button
              type="button"
              onClick={() => setCurrentPage('sessions')}
              className={`w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded transition-colors ${
                currentPage === 'sessions'
                  ? 'bg-[#20a8d8] text-white'
                  : 'text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              <Settings className="w-4 h-4" />
              <span>Router Sessions</span>
            </button>
          </div>

          {/* Quick Expiration Simulation Bar in Sidebar Footer */}
          <div className="p-3 border-t border-[#3a4149] space-y-2 bg-[#1e2125]/60">
            <div className="text-[11px] font-semibold text-slate-300">
              Router-Side Expiry Verification
            </div>
            <button
              type="button"
              onClick={() => handleRunSchedulerSweep(0)}
              className="w-full py-1.5 px-2.5 bg-[#4dbd74] hover:bg-[#3ea662] text-white text-xs font-semibold rounded flex items-center justify-center gap-1.5 transition-colors"
            >
              <Play className="w-3.5 h-3.5" />
              <span>Run Expiry Monitor Sweep</span>
            </button>
            <button
              type="button"
              onClick={() => handleRunSchedulerSweep(4)}
              className="w-full py-1.5 px-2.5 bg-[#f8cb00] hover:bg-[#dfa700] text-slate-950 text-xs font-semibold rounded flex items-center justify-center gap-1.5 transition-colors"
            >
              <Clock className="w-3.5 h-3.5" />
              <span>Advance Clock +4h & Sweep</span>
            </button>
          </div>
        </aside>

        {/* Main Workspace Content */}
        <main className="flex-1 p-4 overflow-y-auto space-y-4">
          {/* Status Notification Banner */}
          {statusMessage && (
            <div
              className={`px-4 py-2.5 rounded border text-xs flex items-center justify-between gap-3 ${
                statusMessage.type === 'error'
                  ? 'bg-red-950/70 border-red-500/80 text-red-200'
                  : statusMessage.type === 'warning'
                  ? 'bg-amber-950/70 border-amber-500/80 text-amber-200'
                  : 'bg-emerald-950/70 border-emerald-500/80 text-emerald-200'
              }`}
            >
              <span>{statusMessage.text}</span>
              <button
                type="button"
                onClick={() => setStatusMessage(null)}
                className="text-xs underline shrink-0"
              >
                Dismiss
              </button>
            </div>
          )}

          {/* PAGE: DASHBOARD (Authentic Mikhmon v3 home.php layout) */}
          {currentPage === 'dashboard' && (
            <div className="space-y-4">
              {/* Row 1: System Clock, Board Resource, CPU/Memory */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div className="bg-[#272b30] border border-[#3a4149] rounded p-3.5 flex items-center gap-3.5">
                  <div className="p-3 rounded bg-[#20a8d8]/20 text-[#20a8d8]">
                    <Calendar className="w-6 h-6" />
                  </div>
                  <div className="text-xs space-y-0.5 font-mono tabular-nums">
                    <div className="text-slate-400 font-sans">System Date & Time</div>
                    <div className="text-white font-semibold">
                      {currentRouter.systemDate} {currentRouter.systemTime}
                    </div>
                    <div className="text-slate-300">
                      Uptime: {currentRouter.uptime} · TZ: {currentRouter.timezone}
                    </div>
                  </div>
                </div>

                <div className="bg-[#272b30] border border-[#3a4149] rounded p-3.5 flex items-center gap-3.5">
                  <div className="p-3 rounded bg-[#4dbd74]/20 text-[#4dbd74]">
                    <Router className="w-6 h-6" />
                  </div>
                  <div className="text-xs space-y-0.5">
                    <div className="text-slate-400">
                      Board: {currentRouter.boardName} ({currentRouter.model})
                    </div>
                    <div className="text-white font-semibold font-mono">
                      RouterOS: {currentRouter.rosVersion}
                    </div>
                    <div className="text-emerald-400 text-[11px]">
                      Universal v6/v7 Expiry Engine Active
                    </div>
                  </div>
                </div>

                <div className="bg-[#272b30] border border-[#3a4149] rounded p-3.5 flex items-center gap-3.5">
                  <div className="p-3 rounded bg-[#f8cb00]/20 text-[#f8cb00]">
                    <Cpu className="w-6 h-6" />
                  </div>
                  <div className="text-xs space-y-0.5 font-mono tabular-nums">
                    <div className="text-white font-semibold">
                      CPU Load: {currentRouter.cpuLoad}%
                    </div>
                    <div className="text-slate-300">
                      Free Memory: {formatBytes(currentRouter.freeMemoryBytes)}
                    </div>
                    <div className="text-slate-300">
                      Free HDD: {formatBytes(currentRouter.freeHddBytes)}
                    </div>
                  </div>
                </div>
              </div>

              {/* Row 2: Mikhmon 4 Colored Hotspot Tiles + Live Selling Report */}
              <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
                <div className="lg:col-span-8 space-y-4">
                  <div className="bg-[#272b30] border border-[#3a4149] rounded p-4">
                    <div className="flex items-center justify-between mb-3">
                      <h3 className="text-sm font-semibold text-white flex items-center gap-2">
                        <Wifi className="w-4 h-4 text-[#20a8d8]" />
                        <span>Hotspot Summary ({currentRouter.hotspotName})</span>
                      </h3>
                      <span className="text-xs text-slate-400 font-mono">
                        Session: {currentRouter.id}
                      </span>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                      <button
                        type="button"
                        onClick={() => setCurrentPage('active')}
                        className="bg-[#20a8d8] hover:bg-[#1985ac] text-white rounded p-3 text-left transition-colors"
                      >
                        <div className="text-2xl font-bold font-mono tabular-nums">
                          {routerActiveSessions.length}{' '}
                          <span className="text-xs font-normal">items</span>
                        </div>
                        <div className="text-xs mt-1 font-medium">Hotspot Active</div>
                      </button>

                      <button
                        type="button"
                        onClick={() => setCurrentPage('users')}
                        className="bg-[#4dbd74] hover:bg-[#3ea662] text-white rounded p-3 text-left transition-colors"
                      >
                        <div className="text-2xl font-bold font-mono tabular-nums">
                          {routerUsers.length}{' '}
                          <span className="text-xs font-normal">items</span>
                        </div>
                        <div className="text-xs mt-1 font-medium">Hotspot Users</div>
                      </button>

                      <button
                        type="button"
                        onClick={() => setCurrentPage('add-user')}
                        className="bg-[#f8cb00] hover:bg-[#dfa700] text-slate-950 rounded p-3 text-left transition-colors"
                      >
                        <div className="text-2xl font-bold">Add</div>
                        <div className="text-xs mt-1 font-semibold">Single User</div>
                      </button>

                      <button
                        type="button"
                        onClick={() => setCurrentPage('generate')}
                        className="bg-[#f86c6b] hover:bg-[#e15352] text-white rounded p-3 text-left transition-colors"
                      >
                        <div className="text-2xl font-bold">Generate</div>
                        <div className="text-xs mt-1 font-medium">Batch Vouchers</div>
                      </button>
                    </div>
                  </div>

                  {/* Quick RouterOS 6 vs RouterOS 7 Expiration Live Workbench Card */}
                  <div className="bg-[#272b30] border border-[#3a4149] rounded p-4 space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <h3 className="text-sm font-semibold text-white">
                          RouterOS {currentRouter.rosMajor}.x Expiration Monitor & Profile Health
                        </h3>
                        <p className="text-xs text-slate-300">
                          Verify first-login expiry calculation, Remove &amp; Record sales script creation, and active session disconnection.
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => handleRunSchedulerSweep(0)}
                          className="px-3 py-1.5 bg-[#4dbd74] hover:bg-[#3ea662] text-white text-xs font-semibold rounded transition-colors"
                        >
                          Execute Monitor Sweep Now
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMigrateRouterProfiles(currentRouter.id)}
                          className="px-3 py-1.5 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-xs font-semibold rounded transition-colors"
                        >
                          Migrate Legacy Profiles
                        </button>
                      </div>
                    </div>

                    <div className="overflow-x-auto">
                      <table className="w-full text-left border-collapse text-xs">
                        <thead>
                          <tr className="bg-[#1e2125] text-slate-300 border-b border-[#3a4149]">
                            <th className="py-2 px-3">Profile Name</th>
                            <th className="py-2 px-3">Expired Mode</th>
                            <th className="py-2 px-3">Validity</th>
                            <th className="py-2 px-3">Price</th>
                            <th className="py-2 px-3">Script Engine</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-[#3a4149]">
                          {routerProfiles.map((p) => (
                            <tr key={p.id} className="hover:bg-[#2e3338]">
                              <td className="py-2 px-3 font-medium text-white">{p.name}</td>
                              <td className="py-2 px-3 text-slate-300">
                                {p.expMode === 'remc'
                                  ? 'Remove & Record'
                                  : p.expMode === 'ntfc'
                                  ? 'Notice & Record'
                                  : p.expMode === 'rem'
                                  ? 'Remove'
                                  : p.expMode === 'ntf'
                                  ? 'Notice'
                                  : 'None'}
                              </td>
                              <td className="py-2 px-3 font-mono text-cyan-300">{p.validity}</td>
                              <td className="py-2 px-3 font-mono tabular-nums">
                                {currentRouter.currency} {p.price.toLocaleString('id-ID')}
                              </td>
                              <td className="py-2 px-3 font-mono">
                                {p.scriptVersion === 'legacy_ros6' ? (
                                  <span className="text-amber-400">
                                    Legacy ROS6 (Click Migrate)
                                  </span>
                                ) : (
                                  <span className="text-emerald-400">
                                    Universal ROS6 + ROS7 Active
                                  </span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                {/* Right Column: Live Income Report & Recent Hotspot Log */}
                <div className="lg:col-span-4 space-y-4">
                  <div className="bg-[#272b30] border border-[#3a4149] rounded p-4">
                    <div className="flex items-center gap-3">
                      <div className="p-3 rounded bg-emerald-500/20 text-emerald-400">
                        <DollarSign className="w-6 h-6" />
                      </div>
                      <div className="text-xs space-y-1 font-mono tabular-nums">
                        <div className="text-slate-400 font-sans font-semibold">
                          Live Selling Report (Normalized v6/v7)
                        </div>
                        <div className="text-white">
                          Today {liveReportSummary.todayCount} vcr : {currentRouter.currency}{' '}
                          {liveReportSummary.todayIncome.toLocaleString('id-ID')}
                        </div>
                        <div className="text-emerald-400 font-semibold">
                          This Month {liveReportSummary.monthCount} vcr : {currentRouter.currency}{' '}
                          {liveReportSummary.monthIncome.toLocaleString('id-ID')}
                        </div>
                      </div>
                    </div>
                  </div>

                  <div className="bg-[#272b30] border border-[#3a4149] rounded p-4 space-y-2.5">
                    <div className="flex items-center justify-between">
                      <h3 className="text-xs font-semibold text-white">
                        Hotspot Active & Expiry Status ({currentRouter.id})
                      </h3>
                      <button
                        type="button"
                        onClick={() => setCurrentPage('users')}
                        className="text-[11px] text-[#20a8d8] hover:underline"
                      >
                        Open Users
                      </button>
                    </div>
                    <div className="space-y-2">
                      {routerUsers.slice(0, 5).map((u) => {
                        const ev = evaluateUserExpiration(
                          u,
                          currentRouter.systemDate,
                          currentRouter.systemTime
                        );
                        return (
                          <div
                            key={u.id}
                            className="bg-[#1e2125] border border-[#3a4149] rounded p-2.5 text-xs flex items-center justify-between gap-2"
                          >
                            <div className="min-w-0">
                              <div className="font-mono font-semibold text-white truncate">
                                {u.name}{' '}
                                <span className="text-slate-400 font-normal">({u.profile})</span>
                              </div>
                              <div className="text-[11px] font-mono text-slate-400 truncate">
                                {u.comment}
                              </div>
                            </div>
                            <div className="shrink-0 flex items-center gap-1.5">
                              {ev.reason === 'unactivated' ? (
                                <button
                                  type="button"
                                  onClick={() => handleSimulateFirstLogin(u)}
                                  className="px-2 py-1 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-[11px] font-semibold rounded"
                                >
                                  Simulate Login
                                </button>
                              ) : ev.isExpired ? (
                                <span className="text-red-400 font-mono text-[11px] font-semibold">
                                  EXPIRED
                                </span>
                              ) : (
                                <span className="text-emerald-400 font-mono text-[11px]">
                                  VALID
                                </span>
                              )}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* PAGE: HOTSPOT USERS LIST (Authentic hotspot/users.php) */}
          {currentPage === 'users' && (
            <div className="bg-[#272b30] border border-[#3a4149] rounded overflow-hidden">
              <div className="p-3.5 border-b border-[#3a4149] flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="text-sm font-semibold text-white">
                    Hotspot Users ({routerUsers.length})
                  </h3>
                  <span className="text-slate-500">|</span>
                  <button
                    type="button"
                    onClick={() => setCurrentPage('add-user')}
                    className="text-xs text-[#20a8d8] hover:underline font-medium"
                  >
                    + Add User
                  </button>
                  <span className="text-slate-500">|</span>
                  <button
                    type="button"
                    onClick={() => setCurrentPage('generate')}
                    className="text-xs text-[#4dbd74] hover:underline font-medium"
                  >
                    + Generate Vouchers
                  </button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={() => handleRunSchedulerSweep(0)}
                    className="px-2.5 py-1 bg-[#4dbd74] hover:bg-[#3ea662] text-white text-xs font-semibold rounded"
                  >
                    Run Monitor Sweep
                  </button>
                  <button
                    type="button"
                    onClick={handleRemoveAllExpiredUsers}
                    className="px-2.5 py-1 bg-[#f86c6b] hover:bg-[#e15352] text-white text-xs font-semibold rounded"
                  >
                    Remove Expired Users (1s / 00:00:01)
                  </button>
                  <button
                    type="button"
                    onClick={() => setPrintVoucherBatch(routerUsers)}
                    className="px-2.5 py-1 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-xs font-semibold rounded"
                  >
                    Print Vouchers
                  </button>
                </div>
              </div>

              {/* Filter Bar */}
              <div className="p-3 bg-[#1e2125] border-b border-[#3a4149] grid grid-cols-1 sm:grid-cols-4 gap-2.5">
                <input
                  type="text"
                  value={userSearchQuery}
                  onChange={(e) => setUserSearchQuery(e.target.value)}
                  placeholder="Search user, comment, or expiry date..."
                  className="bg-[#272b30] border border-[#3a4149] rounded px-3 py-1.5 text-xs text-white"
                />
                <select
                  aria-label="Filter by Profile"
                  value={userFilterProfile}
                  onChange={(e) => setUserFilterProfile(e.target.value)}
                  className="bg-[#272b30] border border-[#3a4149] rounded px-3 py-1.5 text-xs text-white"
                >
                  <option value="all">Profile: Show All</option>
                  {routerProfiles.map((p) => (
                    <option key={p.id} value={p.name}>
                      Profile: {p.name}
                    </option>
                  ))}
                </select>
                <label className="flex items-center gap-2 text-xs text-slate-300 px-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={userExpiredOnly}
                    onChange={(e) => setUserExpiredOnly(e.target.checked)}
                  />
                  <span>Filter Expired Only (ROS6 1s / ROS7 00:00:01 / Past Date)</span>
                </label>
                <div className="text-right text-xs font-mono text-slate-400 self-center tabular-nums">
                  Router Clock: {currentRouter.systemDate} {currentRouter.systemTime}
                </div>
              </div>

              {/* Users Table */}
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-[#1e2125] text-slate-300 border-b border-[#3a4149]">
                      <th className="py-2 px-3">Action</th>
                      <th className="py-2 px-3">Server</th>
                      <th className="py-2 px-3">Name</th>
                      <th className="py-2 px-3">Profile</th>
                      <th className="py-2 px-3">MAC Address</th>
                      <th className="py-2 px-3 text-right">Uptime</th>
                      <th className="py-2 px-3 text-right">Limit Uptime</th>
                      <th className="py-2 px-3">Comment (ROS6 / ROS7 Expiry Metadata)</th>
                      <th className="py-2 px-3">Expiry Status & Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#3a4149]">
                    {routerUsers.map((u) => {
                      const ev = evaluateUserExpiration(
                        u,
                        currentRouter.systemDate,
                        currentRouter.systemTime
                      );
                      const parsed = parseHotspotUserComment(u.comment);
                      return (
                        <tr key={u.id} className="hover:bg-[#2e3338]">
                          <td className="py-2 px-3 whitespace-nowrap">
                            <button
                              type="button"
                              onClick={() => handleDeleteUser(u)}
                              title={`Remove ${u.name}`}
                              className="text-red-400 hover:text-red-300 mr-2 font-semibold"
                            >
                              Delete
                            </button>
                            <button
                              type="button"
                              onClick={() => setPrintVoucherBatch([u])}
                              className="text-cyan-400 hover:text-cyan-300 font-semibold"
                            >
                              Print
                            </button>
                          </td>
                          <td className="py-2 px-3 text-slate-300">{u.server}</td>
                          <td className="py-2 px-3 font-mono font-semibold text-white">
                            {u.name}
                          </td>
                          <td className="py-2 px-3 text-slate-200">{u.profile}</td>
                          <td className="py-2 px-3 font-mono text-slate-400">
                            {u.macAddress || '-'}
                          </td>
                          <td className="py-2 px-3 text-right font-mono tabular-nums text-slate-300">
                            {u.uptime}
                          </td>
                          <td className="py-2 px-3 text-right font-mono tabular-nums">
                            {isLimitUptimeExpiredMarker(u.limitUptime) ? (
                              <span className="text-red-400 font-semibold">
                                {u.limitUptime} (expired)
                              </span>
                            ) : (
                              <span className="text-slate-300">{u.limitUptime || '-'}</span>
                            )}
                          </td>
                          <td className="py-2 px-3 font-mono text-[11px] text-cyan-300">
                            {u.comment}
                          </td>
                          <td className="py-2 px-3 whitespace-nowrap">
                            {parsed.isUnactivatedVoucher ? (
                              <button
                                type="button"
                                onClick={() => handleSimulateFirstLogin(u)}
                                className="px-2 py-1 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-[11px] font-semibold rounded"
                              >
                                Simulate 1st Login
                              </button>
                            ) : ev.isExpired ? (
                              <span className="text-red-400 font-mono text-[11px] font-semibold">
                                EXPIRED ({ev.reason})
                              </span>
                            ) : (
                              <span className="text-emerald-400 font-mono text-[11px]">
                                ACTIVE ({parsed.format.toUpperCase()})
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* PAGE: GENERATE VOUCHERS (Authentic hotspot/generateuser.php) */}
          {currentPage === 'generate' && (
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
              <div className="lg:col-span-8 bg-[#272b30] border border-[#3a4149] rounded p-4">
                <div className="flex items-center justify-between border-b border-[#3a4149] pb-3 mb-4">
                  <h3 className="text-sm font-semibold text-white">
                    Generate Batch Hotspot Vouchers ({currentRouter.id})
                  </h3>
                  <button
                    type="button"
                    onClick={() => setCurrentPage('users')}
                    className="px-3 py-1 bg-[#f8cb00] text-slate-950 text-xs font-semibold rounded"
                  >
                    Close
                  </button>
                </div>

                <form onSubmit={handleGenerateVouchers} className="space-y-3 text-xs">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-slate-300 mb-1">Qty (Batch Count)</label>
                      <input
                        type="number"
                        min={1}
                        max={500}
                        value={genQty}
                        onChange={(e) => setGenQty(parseInt(e.target.value, 10) || 1)}
                        className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                      />
                    </div>
                    <div>
                      <label className="block text-slate-300 mb-1">User Mode</label>
                      <select
                        value={genMode}
                        onChange={(e) => setGenMode(e.target.value as 'vc' | 'up')}
                        className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white"
                      >
                        <option value="vc">Username = Password (Voucher vc-)</option>
                        <option value="up">Username &amp; Password (Member up-)</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-slate-300 mb-1">Character Length</label>
                      <select
                        value={genLength}
                        onChange={(e) => setGenLength(parseInt(e.target.value, 10))}
                        className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                      >
                        <option value={4}>4</option>
                        <option value={5}>5</option>
                        <option value={6}>6</option>
                        <option value={7}>7</option>
                        <option value={8}>8</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-slate-300 mb-1">Prefix</label>
                      <input
                        type="text"
                        maxLength={6}
                        value={genPrefix}
                        onChange={(e) => setGenPrefix(e.target.value)}
                        className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                      />
                    </div>
                    <div>
                      <label className="block text-slate-300 mb-1">Hotspot User Profile</label>
                      <select
                        value={genProfile}
                        onChange={(e) => setGenProfile(e.target.value)}
                        className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white"
                      >
                        {routerProfiles.map((p) => (
                          <option key={p.id} value={p.name}>
                            {p.name} (Validity: {p.validity} · {currentRouter.currency}{' '}
                            {p.price.toLocaleString('id-ID')})
                          </option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="block text-slate-300 mb-1">
                        Time Limit (Limit-Uptime, Optional)
                      </label>
                      <input
                        type="text"
                        value={genTimeLimit}
                        onChange={(e) => setGenTimeLimit(e.target.value)}
                        placeholder="e.g. 3h, 1d (leave blank for pure validity)"
                        className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">Additional Comment Note</label>
                    <input
                      type="text"
                      value={genComment}
                      onChange={(e) => setGenComment(e.target.value)}
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                    />
                  </div>
                  <div className="pt-2">
                    <button
                      type="submit"
                      className="px-4 py-2 bg-[#20a8d8] hover:bg-[#1985ac] text-white font-semibold rounded transition-colors"
                    >
                      Generate Vouchers Now
                    </button>
                  </div>
                </form>
              </div>

              <div className="lg:col-span-4 bg-[#272b30] border border-[#3a4149] rounded p-4 space-y-2 text-xs">
                <h4 className="text-sm font-semibold text-white">
                  First-Login &amp; Reseller Quota Rules
                </h4>
                <p className="text-slate-300">
                  Generated vouchers receive a comment tag (<code>vc-XXX-mm.dd.yy-note</code>). Their validity countdown starts strictly upon <strong>first successful Hotspot login</strong>.
                </p>
                {actorRole === 'reseller' && activeReseller && (
                  <div className="bg-[#1e2125] border border-[#3a4149] rounded p-3 space-y-1 font-mono">
                    <div className="text-emerald-400 font-semibold">
                      Active Reseller: {activeReseller.username}
                    </div>
                    <div>Max Batch Limit: {activeReseller.maxVoucherBatchQty} vouchers</div>
                    <div>
                      Wallet Balance: Rp {activeReseller.walletBalance.toLocaleString('id-ID')}
                    </div>
                    <div>
                      Permitted Profiles: {activeReseller.allowedProfiles.join(', ')}
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* PAGE: ADD SINGLE USER (Authentic hotspot/adduser.php) */}
          {currentPage === 'add-user' && (
            <div className="max-w-2xl bg-[#272b30] border border-[#3a4149] rounded p-4">
              <div className="flex items-center justify-between border-b border-[#3a4149] pb-3 mb-4">
                <h3 className="text-sm font-semibold text-white">
                  Add Single Hotspot User ({currentRouter.id})
                </h3>
                <button
                  type="button"
                  onClick={() => setCurrentPage('users')}
                  className="px-3 py-1 bg-[#f8cb00] text-slate-950 text-xs font-semibold rounded"
                >
                  Close
                </button>
              </div>
              <form onSubmit={handleAddSingleUser} className="space-y-3 text-xs">
                <div>
                  <label className="block text-slate-300 mb-1">Username</label>
                  <input
                    type="text"
                    value={addUName}
                    onChange={(e) => setAddUName(e.target.value)}
                    placeholder="e.g. guest204"
                    className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                  />
                </div>
                <div>
                  <label className="block text-slate-300 mb-1">
                    Password (same as username for vc- mode)
                  </label>
                  <input
                    type="text"
                    value={addUPass}
                    onChange={(e) => setAddUPass(e.target.value)}
                    placeholder="e.g. guest204"
                    className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                  />
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-slate-300 mb-1">Profile</label>
                    <select
                      value={addUProfile}
                      onChange={(e) => setAddUProfile(e.target.value)}
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white"
                    >
                      {routerProfiles.map((p) => (
                        <option key={p.id} value={p.name}>
                          {p.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">Time Limit (Limit-Uptime)</label>
                    <input
                      type="text"
                      value={addUTimeLimit}
                      onChange={(e) => setAddUTimeLimit(e.target.value)}
                      placeholder="e.g. 3h, 1d"
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-slate-300 mb-1">Comment</label>
                  <input
                    type="text"
                    value={addUComment}
                    onChange={(e) => setAddUComment(e.target.value)}
                    placeholder="Room 204"
                    className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                  />
                </div>
                <button
                  type="submit"
                  className="px-4 py-2 bg-[#20a8d8] hover:bg-[#1985ac] text-white font-semibold rounded"
                >
                  Save Hotspot User
                </button>
              </form>
            </div>
          )}

          {/* PAGE: USER PROFILES (Authentic hotspot/userprofile.php) */}
          {currentPage === 'user-profiles' && (
            <div className="bg-[#272b30] border border-[#3a4149] rounded overflow-hidden">
              <div className="p-3.5 border-b border-[#3a4149] flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-3">
                  <h3 className="text-sm font-semibold text-white">
                    Hotspot User Profiles ({routerProfiles.length})
                  </h3>
                  <button
                    type="button"
                    onClick={() => setCurrentPage('add-profile')}
                    className="px-2.5 py-1 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-xs font-semibold rounded"
                  >
                    + Add Profile
                  </button>
                </div>
                <button
                  type="button"
                  onClick={() => handleMigrateRouterProfiles(currentRouter.id)}
                  className="px-3 py-1 bg-[#4dbd74] hover:bg-[#3ea662] text-white text-xs font-semibold rounded"
                >
                  Migrate All Legacy ROS6 Profiles to Universal v6/v7
                </button>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-[#1e2125] text-slate-300 border-b border-[#3a4149]">
                      <th className="py-2 px-3">Name</th>
                      <th className="py-2 px-3">Shared Users</th>
                      <th className="py-2 px-3">Rate Limit</th>
                      <th className="py-2 px-3">Expired Mode</th>
                      <th className="py-2 px-3">Validity</th>
                      <th className="py-2 px-3 text-right">Price ({currentRouter.currency})</th>
                      <th className="py-2 px-3 text-right">
                        Selling Price ({currentRouter.currency})
                      </th>
                      <th className="py-2 px-3">Lock User</th>
                      <th className="py-2 px-3">ROS6/ROS7 Script Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#3a4149]">
                    {routerProfiles.map((p) => (
                      <tr key={p.id} className="hover:bg-[#2e3338]">
                        <td className="py-2 px-3 font-semibold text-white">{p.name}</td>
                        <td className="py-2 px-3 font-mono">{p.sharedUsers}</td>
                        <td className="py-2 px-3 font-mono text-slate-300">{p.rateLimit}</td>
                        <td className="py-2 px-3">
                          {p.expMode === 'remc'
                            ? 'Remove & Record'
                            : p.expMode === 'ntfc'
                            ? 'Notice & Record'
                            : p.expMode === 'rem'
                            ? 'Remove'
                            : p.expMode === 'ntf'
                            ? 'Notice'
                            : 'None'}
                        </td>
                        <td className="py-2 px-3 font-mono text-cyan-300">{p.validity}</td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums">
                          {p.price.toLocaleString('id-ID')}
                        </td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums">
                          {p.sellingPrice.toLocaleString('id-ID')}
                        </td>
                        <td className="py-2 px-3">{p.lockUser}</td>
                        <td className="py-2 px-3 font-mono">
                          {p.scriptVersion === 'legacy_ros6' ? (
                            <span className="text-amber-400">Legacy ROS6 (Needs Migration)</span>
                          ) : (
                            <span className="text-emerald-400">Universal ROS6/ROS7</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* PAGE: ADD USER PROFILE (Authentic hotspot/adduserprofile.php) */}
          {currentPage === 'add-profile' && (
            <div className="max-w-2xl bg-[#272b30] border border-[#3a4149] rounded p-4">
              <div className="flex items-center justify-between border-b border-[#3a4149] pb-3 mb-4">
                <h3 className="text-sm font-semibold text-white">
                  Add Hotspot User Profile (Universal ROS6/ROS7 Script Generator)
                </h3>
                <button
                  type="button"
                  onClick={() => setCurrentPage('user-profiles')}
                  className="px-3 py-1 bg-[#f8cb00] text-slate-950 text-xs font-semibold rounded"
                >
                  Close
                </button>
              </div>
              <form onSubmit={handleAddProfile} className="space-y-3 text-xs">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-slate-300 mb-1">Profile Name</label>
                    <input
                      type="text"
                      value={profName}
                      onChange={(e) => setProfName(e.target.value)}
                      placeholder="e.g. Paket-12Jam"
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white"
                    />
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">Rate Limit [up/down]</label>
                    <input
                      type="text"
                      value={profRate}
                      onChange={(e) => setProfRate(e.target.value)}
                      placeholder="5M/10M"
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                    />
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">Expired Mode</label>
                    <select
                      value={profExpMode}
                      onChange={(e) => setProfExpMode(e.target.value as ExpirationMode)}
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white"
                    >
                      <option value="remc">Remove &amp; Record</option>
                      <option value="ntfc">Notice &amp; Record</option>
                      <option value="rem">Remove</option>
                      <option value="ntf">Notice</option>
                      <option value="0">None</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">Validity (e.g. 3h, 1d, 30d)</label>
                    <input
                      type="text"
                      value={profValidity}
                      onChange={(e) => setProfValidity(e.target.value)}
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                    />
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">
                      Price ({currentRouter.currency})
                    </label>
                    <input
                      type="number"
                      value={profPrice}
                      onChange={(e) => setProfPrice(e.target.value)}
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white font-mono"
                    />
                  </div>
                  <div>
                    <label className="block text-slate-300 mb-1">Lock User to MAC</label>
                    <select
                      value={profLock}
                      onChange={(e) => setProfLock(e.target.value as LockUserMode)}
                      className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-3 py-1.5 text-white"
                    >
                      <option value="Enable">Enable</option>
                      <option value="Disable">Disable</option>
                    </select>
                  </div>
                </div>
                <button
                  type="submit"
                  className="px-4 py-2 bg-[#20a8d8] hover:bg-[#1985ac] text-white font-semibold rounded"
                >
                  Save Profile &amp; Provision Monitor Scheduler
                </button>
              </form>
            </div>
          )}

          {/* PAGE: HOTSPOT ACTIVE (Authentic hotspot/hotspotactive.php) */}
          {currentPage === 'active' && (
            <div className="bg-[#272b30] border border-[#3a4149] rounded overflow-hidden">
              <div className="p-3.5 border-b border-[#3a4149] flex items-center justify-between">
                <h3 className="text-sm font-semibold text-white">
                  Hotspot Active Sessions ({routerActiveSessions.length} items)
                </h3>
                <button
                  type="button"
                  onClick={() => handleRunSchedulerSweep(0)}
                  className="px-3 py-1 bg-[#4dbd74] hover:bg-[#3ea662] text-white text-xs font-semibold rounded"
                >
                  Run Expiry Sweep to Disconnect Expired Active Users
                </button>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-[#1e2125] text-slate-300 border-b border-[#3a4149]">
                      <th className="py-2 px-3">Action</th>
                      <th className="py-2 px-3">Server</th>
                      <th className="py-2 px-3">User</th>
                      <th className="py-2 px-3">Address</th>
                      <th className="py-2 px-3">MAC Address</th>
                      <th className="py-2 px-3 text-right">Uptime</th>
                      <th className="py-2 px-3 text-right">Bytes In</th>
                      <th className="py-2 px-3 text-right">Bytes Out</th>
                      <th className="py-2 px-3">Comment (Expiry Timestamp)</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#3a4149]">
                    {routerActiveSessions.map((act) => (
                      <tr key={act.id} className="hover:bg-[#2e3338]">
                        <td className="py-2 px-3">
                          <button
                            type="button"
                            onClick={() => {
                              const perm = verifyResellerAction({
                                actorRole,
                                reseller: activeReseller,
                                targetRouterId: currentRouter.id,
                                action: 'disconnectActive',
                              });
                              if (!perm.allowed) {
                                setStatusMessage({
                                  type: 'error',
                                  text: `Reseller Restriction: ${perm.reason}`,
                                });
                                return;
                              }
                              setActiveSessions((prev) => prev.filter((a) => a.id !== act.id));
                              setStatusMessage({
                                type: 'success',
                                text: `Disconnected active session for user "${act.user}".`,
                              });
                            }}
                            className="text-red-400 hover:text-red-300 font-semibold"
                          >
                            Disconnect
                          </button>
                        </td>
                        <td className="py-2 px-3 text-slate-300">{act.server}</td>
                        <td className="py-2 px-3 font-mono font-semibold text-white">
                          {act.user}
                        </td>
                        <td className="py-2 px-3 font-mono text-slate-300">{act.address}</td>
                        <td className="py-2 px-3 font-mono text-slate-400">{act.macAddress}</td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums">
                          {act.uptime}
                        </td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums">
                          {formatBytes(act.bytesIn)}
                        </td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums">
                          {formatBytes(act.bytesOut)}
                        </td>
                        <td className="py-2 px-3 font-mono text-cyan-300">{act.comment}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* PAGE: SYSTEM SCHEDULER (Authentic system/scheduler.php) */}
          {currentPage === 'scheduler' && (
            <div className="bg-[#272b30] border border-[#3a4149] rounded overflow-hidden">
              <div className="p-3.5 border-b border-[#3a4149] flex items-center justify-between">
                <h3 className="text-sm font-semibold text-white">
                  System Scheduler — Profile Expiration Monitors ({routerSchedulers.length} items)
                </h3>
                <button
                  type="button"
                  onClick={() => handleRunSchedulerSweep(0)}
                  className="px-3 py-1 bg-[#4dbd74] hover:bg-[#3ea662] text-white text-xs font-semibold rounded"
                >
                  Trigger All Monitor Schedulers Now
                </button>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-[#1e2125] text-slate-300 border-b border-[#3a4149]">
                      <th className="py-2 px-3">Name</th>
                      <th className="py-2 px-3">Start Date</th>
                      <th className="py-2 px-3">Start Time</th>
                      <th className="py-2 px-3">Interval</th>
                      <th className="py-2 px-3">Next Run</th>
                      <th className="py-2 px-3 text-right">Run Count</th>
                      <th className="py-2 px-3">Script Compatibility</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#3a4149]">
                    {routerSchedulers.map((sch) => (
                      <tr key={sch.id} className="hover:bg-[#2e3338]">
                        <td className="py-2 px-3 font-semibold text-white">{sch.name}</td>
                        <td className="py-2 px-3 font-mono">{sch.startDate}</td>
                        <td className="py-2 px-3 font-mono">{sch.startTime}</td>
                        <td className="py-2 px-3 font-mono">{sch.interval}</td>
                        <td className="py-2 px-3 font-mono text-cyan-300">{sch.nextRun}</td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums">
                          {sch.runCount}
                        </td>
                        <td className="py-2 px-3 font-mono">
                          {sch.scriptVersion === 'legacy_ros6' ? (
                            <span className="text-amber-400">Legacy ROS6 Only</span>
                          ) : (
                            <span className="text-emerald-400">Universal ROS6 + ROS7</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* PAGE: SELLING REPORT (Authentic report/selling.php) */}
          {currentPage === 'selling' && (
            <div className="bg-[#272b30] border border-[#3a4149] rounded overflow-hidden">
              <div className="p-3.5 border-b border-[#3a4149] flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 className="text-sm font-semibold text-white">
                    Selling Report &amp; Remove &amp; Record History ({routerSalesRecords.length}{' '}
                    records)
                  </h3>
                  <p className="text-xs text-slate-400">
                    Records stored in <code>/system script</code> with normalized <code>owner=&quot;mmmyyyy&quot;</code> and <code>source=&quot;mmm/dd/yyyy&quot;</code> across both ROS 6 and ROS 7.
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    value={reportSearchPrefix}
                    onChange={(e) => setReportSearchPrefix(e.target.value)}
                    placeholder="Filter by user prefix..."
                    className="bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1 text-xs text-white"
                  />
                  <select
                    value={reportFilterMonth}
                    onChange={(e) => setReportFilterMonth(e.target.value)}
                    className="bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1 text-xs text-white font-mono"
                  >
                    <option value="oct2026">Month: oct2026</option>
                    <option value="all">All Months</option>
                  </select>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-[#1e2125] text-slate-300 border-b border-[#3a4149]">
                      <th className="py-2 px-3">#</th>
                      <th className="py-2 px-3">Date (source)</th>
                      <th className="py-2 px-3">Time</th>
                      <th className="py-2 px-3">Username</th>
                      <th className="py-2 px-3">Profile</th>
                      <th className="py-2 px-3">Owner Key</th>
                      <th className="py-2 px-3">Reseller / Operator</th>
                      <th className="py-2 px-3 text-right">
                        Price ({currentRouter.currency})
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#3a4149]">
                    {routerSalesRecords.map((rec, idx) => (
                      <tr key={rec.id} className="hover:bg-[#2e3338]">
                        <td className="py-2 px-3 font-mono text-slate-400">{idx + 1}</td>
                        <td className="py-2 px-3 font-mono text-white">
                          {rec.dateNormalized}{' '}
                          <span className="text-slate-400">({rec.isoDate})</span>
                        </td>
                        <td className="py-2 px-3 font-mono text-slate-300">{rec.time}</td>
                        <td className="py-2 px-3 font-mono font-semibold text-cyan-300">
                          {rec.username}
                        </td>
                        <td className="py-2 px-3">{rec.profile}</td>
                        <td className="py-2 px-3 font-mono text-emerald-400">{rec.owner}</td>
                        <td className="py-2 px-3 font-mono text-slate-300">
                          {rec.reseller || 'admin'}
                        </td>
                        <td className="py-2 px-3 text-right font-mono tabular-nums font-semibold text-white">
                          {rec.price.toLocaleString('id-ID')}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* PAGE: RESELLER MANAGEMENT & RBAC */}
          {currentPage === 'resellers' && (
            <ResellerManagementView
              resellers={resellers}
              routers={routers}
              profiles={profiles}
              actorRole={actorRole}
              activeResellerId={activeResellerId}
              onSelectActor={handleSelectActor}
              onSaveReseller={(updated) => {
                setResellers((prev) => {
                  const exists = prev.some((r) => r.id === updated.id);
                  if (exists) {
                    return prev.map((r) => (r.id === updated.id ? updated : r));
                  }
                  return [...prev, updated];
                });
              }}
              onToggleResellerStatus={(resellerId) => {
                setResellers((prev) =>
                  prev.map((r) =>
                    r.id === resellerId
                      ? { ...r, status: r.status === 'active' ? 'suspended' : 'active' }
                      : r
                  )
                );
              }}
            />
          )}

          {/* PAGE: ROS6/ROS7 EXPIRY AUDIT & 20-SCENARIO TEST MATRIX */}
          {currentPage === 'ros7-audit' && (
            <AuditAndTestMatrixView
              routers={routers}
              profiles={profiles}
              users={users}
              activeSessions={activeSessions}
              schedulers={schedulers}
              salesRecords={salesRecords}
              resellers={resellers}
              onMigrateRouterProfiles={handleMigrateRouterProfiles}
            />
          )}

          {/* PAGE: ROUTER SESSIONS (Authentic settings/sessions.php) */}
          {currentPage === 'sessions' && (
            <div className="bg-[#272b30] border border-[#3a4149] rounded p-4 space-y-4">
              <h3 className="text-sm font-semibold text-white">
                Configured MikroTik Router Sessions (ROS 6.x &amp; ROS 7.x)
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                {routers.map((rt) => (
                  <div
                    key={rt.id}
                    className={`p-4 rounded border ${
                      rt.id === currentRouter.id
                        ? 'bg-[#32383e] border-[#20a8d8]'
                        : 'bg-[#1e2125] border-[#3a4149]'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold text-white">{rt.hotspotName}</span>
                      <span className="text-[11px] font-mono text-cyan-400">
                        ROS {rt.rosVersion}
                      </span>
                    </div>
                    <div className="text-xs text-slate-300 font-mono mt-1">
                      Session ID: {rt.id}
                    </div>
                    <div className="text-xs text-slate-400 font-mono">
                      IP: {rt.ipHost} · DNS: {rt.dnsName}
                    </div>
                    <div className="mt-3">
                      <button
                        type="button"
                        onClick={() => handleSelectRouterSession(rt.id)}
                        className="w-full py-1.5 px-3 bg-[#20a8d8] hover:bg-[#1985ac] text-white text-xs font-semibold rounded"
                      >
                        Connect / Open Session
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </main>
      </div>

      {/* Authentic Mikhmon v3 Voucher Print Preview Modal */}
      {printVoucherBatch && (
        <div className="fixed inset-0 bg-black/75 flex items-center justify-center p-4 z-50">
          <div className="bg-white text-black rounded max-w-3xl w-full max-h-[85vh] flex flex-col overflow-hidden">
            <div className="px-4 py-3 bg-[#272b30] text-white flex items-center justify-between">
              <h4 className="text-xs font-semibold">
                Mikhmon v3 Voucher Print Template ({printVoucherBatch.length} vouchers)
              </h4>
              <button
                type="button"
                onClick={() => setPrintVoucherBatch(null)}
                className="px-3 py-1 bg-[#f86c6b] text-white text-xs font-semibold rounded"
              >
                Close Preview
              </button>
            </div>
            <div className="p-4 overflow-y-auto grid grid-cols-1 sm:grid-cols-3 gap-3 bg-slate-100">
              {printVoucherBatch.map((v, idx) => {
                const prof = routerProfiles.find((p) => p.name === v.profile);
                return (
                  <div
                    key={v.id}
                    className="border-2 border-black bg-white p-2.5 text-xs font-sans"
                  >
                    <div className="border-b border-black pb-1 font-bold flex items-center justify-between">
                      <span>{currentRouter.hotspotName}</span>
                      <span className="font-mono">[{idx + 1}]</span>
                    </div>
                    <div className="py-2 text-center">
                      <div className="text-[11px] text-slate-600">Kode Voucher</div>
                      <div className="border border-black py-1 mt-0.5 font-mono font-bold text-sm">
                        {v.name}
                      </div>
                    </div>
                    <div className="border-t border-black pt-1 text-center font-bold">
                      {prof?.validity || '1d'} {v.limitUptime}{' '}
                      {currentRouter.currency} {(prof?.sellingPrice || 5000).toLocaleString('id-ID')}
                    </div>
                    <div className="text-center text-[10px] mt-0.5">
                      Login: http://{currentRouter.dnsName}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
