import net from 'net';
import http from 'http';
import { spawn, execSync, execFile, ChildProcess } from 'child_process';
import path from 'path';

// ============================================================================
// 1. MIKROTIK ROUTEROS BINARY API PROTOCOL ENCODER / DECODER (TCP PORT 8728)
//    Used by Mikhmon's unmodified lib/routeros_api.class.php
// ============================================================================

function encodeLength(len: number): Buffer {
  if (len < 0x80) {
    return Buffer.from([len]);
  } else if (len < 0x4000) {
    len |= 0x8000;
    return Buffer.from([(len >> 8) & 0xff, len & 0xff]);
  } else if (len < 0x200000) {
    len |= 0xc00000;
    return Buffer.from([(len >> 16) & 0xff, (len >> 8) & 0xff, len & 0xff]);
  } else if (len < 0x10000000) {
    len |= 0xe0000000;
    return Buffer.from([
      (len >> 24) & 0xff,
      (len >> 16) & 0xff,
      (len >> 8) & 0xff,
      len & 0xff,
    ]);
  } else {
    return Buffer.from([
      0xf0,
      (len >> 24) & 0xff,
      (len >> 16) & 0xff,
      (len >> 8) & 0xff,
      len & 0xff,
    ]);
  }
}

function encodeWord(word: string): Buffer {
  const strBuf = Buffer.from(word, 'utf8');
  return Buffer.concat([encodeLength(strBuf.length), strBuf]);
}

function sendSentence(socket: net.Socket, words: string[]) {
  const buffers = words.map((w) => encodeWord(w));
  buffers.push(Buffer.from([0x00])); // empty word terminates sentence
  socket.write(Buffer.concat(buffers));
}

function sendRowsAndDone(socket: net.Socket, rows: Record<string, string>[]) {
  for (const row of rows) {
    const words = ['!re'];
    for (const [k, v] of Object.entries(row)) {
      if (v !== undefined && v !== null) {
        words.push(`=${k}=${v}`);
      }
    }
    sendSentence(socket, words);
  }
  sendSentence(socket, ['!done']);
}

// ============================================================================
// 2. ROUTEROS 6.x & 7.x STATEFUL VIRTUAL ROUTERS FOR MIKHMON PHP ENGINE
// ============================================================================

interface RouterState {
  id: string;
  identity: string;
  version: string;
  major: 6 | 7;
  boardName: string;
  model: string;
  cpuLoad: string;
  freeMemory: string;
  totalMemory: string;
  freeHdd: string;
  totalHdd: string;
  uptime: string;
  profiles: Record<string, string>[];
  users: Record<string, string>[];
  active: Record<string, string>[];
  hosts: Record<string, string>[];
  schedulers: Record<string, string>[];
  scripts: Record<string, string>[];
  logs: Record<string, string>[];
  interfaces: Record<string, string>[];
  ipBindings: Record<string, string>[];
  cookies: Record<string, string>[];
  dhcpLeases: Record<string, string>[];
  queues: Record<string, string>[];
  pools: Record<string, string>[];
  hotspots: Record<string, string>[];
  loggingRules: Record<string, string>[];
  pppSecrets: Record<string, string>[];
  pppProfiles: Record<string, string>[];
  pppActive: Record<string, string>[];
  nextIdCounter: number;
  customClockDate?: string;
  customClockTime?: string;
}

function getFormattedClockDate(major: 6 | 7, state?: RouterState): string {
  if (state?.customClockDate) return state.customClockDate;
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  if (major === 7) {
    return `${y}-${m}-${d}`;
  }
  const months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
  return `${months[now.getMonth()]}/${d}/${y}`;
}

function getFormattedClockTime(state?: RouterState): string {
  if (state?.customClockTime) return state.customClockTime;
  const now = new Date();
  return [
    String(now.getHours()).padStart(2, '0'),
    String(now.getMinutes()).padStart(2, '0'),
    String(now.getSeconds()).padStart(2, '0'),
  ].join(':');
}

function buildUniversalOnLogin(
  expmode: string,
  validity: string,
  price: string,
  sprice: string,
  getlock: string,
  major: 6 | 7 = 7
): string {
  if (major === 7) {
    const lockmac = getlock === 'Enable' ? 'YES' : 'NO';
    const addsales = expmode === 'remc' || expmode === 'ntfc' ? 'YES' : 'NO';
    return (
      `:put (",${expmode},${price},${validity},${sprice},,${getlock},");\n\n` +
      `:local validity "${validity}";\n` +
      `:local price "${price}";\n` +
      `:local tlimit "${validity}";\n` +
      `:local lockmac "${lockmac}";\n` +
      `:local addsales "${addsales}";\n\n` +
      `:local date [ /system clock get date ];\n` +
      `:local time [ /system clock get time ];\n` +
      `:local addtime ($time + $validity);\n` +
      `:local days ($addtime / 24h);\n` +
      `:local ndays ($days * 24h);\n` +
      `:local exptime ($addtime - $ndays);\n` +
      `:local comment [ /ip hotspot user get $user comment];\n` +
      `:local hsProfile [/ip hotspot user get $user profile];\n` +
      `:local mac $"mac-address";\n\n` +
      `:if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/") do={} else={\n` +
      `    :local mdays {31;28;31;30;31;30;31;31;30;31;30;31};\n` +
      `    :local months {"01"=1;"02"=2;"03"=3;"04"=4;"05"=5;"06"=6;"07"=7;"08"=8;"09"=9;"10"=10;"11"=11;"12"=12};\n` +
      `    :local monthr {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"};\n` +
      `    :local actmonths {"01"="jan";"02"="feb";"03"="mar";"04"="apr";"05"="may";"06"="jun";"07"="jul";"08"="aug";"09"="sep";"10"="oct";"11"="nov";"12"="dec"};\n` +
      `    :local tday [:tonum [:pick $date 8 10]];\n` +
      `    :local tyear [:tonum [:pick $date 0 4]];\n` +
      `    :local dd [:tonum [:pick $date 8 10]];\n` +
      `    :local yy [:tonum [:pick $date 0 4]];\n` +
      `    :local month [:pick $date 5 7];\n` +
      `    :local year [ :pick $date 0 4 ];\n` +
      `    :local mm (:$months->$month);\n` +
      `    :set dd ($dd+$days);\n` +
      `    :local dm [:pick $mdays ($mm-1)];\n\n` +
      `    :if ($mm=2 && (($yy&3=0 && ($yy/100100 != $yy)) || $yy/400400=$yy)) do={\n` +
      `        :set dm 29\n` +
      `    };\n\n` +
      `    :while ($dd>$dm) do={\n` +
      `        :set dd ($dd-$dm);\n` +
      `        :set mm ($mm+1);\n` +
      `        :if ($mm>12) do={\n` +
      `            :set mm 1;\n` +
      `            :set yy ($yy+1);\n` +
      `        };\n` +
      `        :set dm [:pick $mdays ($mm-1)];\n` +
      `        :if ($mm=2 && (($yy&3=0 && ($yy/100100 != $yy)) || $yy/400400=$yy)) do={\n` +
      `            :set dm 29\n` +
      `        }\n` +
      `    };\n\n` +
      `    :local Tmonth (:$actmonths->$month);\n` +
      `    :local vdate "$[:pick $monthr ($mm-1)]/";\n` +
      `    :if ($dd<10) do={:set vdate ($vdate."0")};\n` +
      `    :set $vdate "$vdate$dd/$yy";\n\n` +
      `    :local zero "";\n` +
      `    :if ([len $tday] = 1) do={:set zero "0";};\n\n` +
      `    /ip hotspot user set comment="$vdate $exptime" [find where name=$user];\n\n` +
      `    :if ($lockmac = "YES") do={\n` +
      `        /ip hotspot user set mac-address=$mac [find where name=$user];\n` +
      `    };\n\n` +
      `    :if ($addsales = "YES") do={\n` +
      `        /system script add name="$Tmonth/$zero$tday/$tyear-|-$time-|-$user-|-$price-|-$address-|-$mac-|-$tlimit-|-$hsProfile-|-$comment" owner="$Tmonth$year" source="$Tmonth/$zero$tday/$tyear" comment=mikhmon;\n` +
      `    };\n` +
      `};`
    );
  }

  const lockPart =
    getlock === 'Enable'
      ? '; :local mac $"mac-address"; :if ([:len $mac] > 0) do={ /ip hotspot user set mac-address=$mac [find where name="$user"] }'
      : '';

  const recordPart =
    expmode === 'remc' || expmode === 'ntfc'
      ? '; :local time [/system clock get time]; :if ([:len [/system script find where comment="mikhmon" and name~("\\-\\|\\-" . $user . "\\-\\|\\-")]] = 0) do={ /system script add name=("$curdate6-|-$time-|-$user-|-' +
        price +
        '-|-$address-|-$mac-|-' +
        validity +
        '-|-$profile-|-$comment") owner=("$curmonth$curyear") source="$curdate6" comment="mikhmon" }'
      : '';

  const onloginBase =
    `:put (",${expmode},${price},${validity},${sprice},,${getlock},"); {:local comment [/ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local cdate [/system clock get date]; :local ctime [/system clock get time]; :local marray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :local curyear ""; :local curmonth ""; :local curday ""; :if ([:pick $cdate 4 5] = "-") do={ :set curyear [:pick $cdate 0 4]; :local mnum [:tonum [:pick $cdate 5 7]]; :set curmonth [:pick $marray ($mnum - 1)]; :set curday [:pick $cdate 8 10]; } else={ :set curyear [:pick $cdate 7 11]; :set curmonth [:pick $cdate 0 3]; :set curday [:pick $cdate 4 6]; }; :local curdate6 ($curmonth . "/" . $curday . "/" . $curyear); :local expFinal ""; /system scheduler remove [find where name="$user"]; /system scheduler add name="$user" disable=no start-date=$cdate start-time=$ctime interval="${validity}" on-event=""; :delay 2s; :local exp [/system scheduler get [/system scheduler find where name="$user"] next-run]; /system scheduler remove [find where name="$user"]; :local getxp [:len $exp]; :if ($getxp > 0) do={ :if ([:pick $exp 4 5] = "-") do={ :local ey [:pick $exp 0 4]; :local emn [:tonum [:pick $exp 5 7]]; :local em [:pick $marray ($emn - 1)]; :local ed [:pick $exp 8 10]; :local et [:pick $exp 11 19]; :set expFinal ($em . "/" . $ed . "/" . $ey . " " . $et); } else={ :if ($getxp = 15) do={ :local d [:pick $exp 0 6]; :local t [:pick $exp 7 15]; :set expFinal ($d . "/" . $curyear . " " . $t); } else={ :if ($getxp = 8) do={ :set expFinal ($curdate6 . " " . $exp); } else={ :set expFinal $exp; }; }; }; }; :if ([:len $expFinal] = 0) do={ :set expFinal ($curdate6 . " " . $ctime); }; /ip hotspot user set comment="$expFinal" [find where name="$user"]`;

  return onloginBase + lockPart + recordPart + '}}';
}

function buildUniversalSchedulerOnEvent(profileName: string, expmode: string, major: 6 | 7 = 7): string {
  const mode = expmode === 'ntf' || expmode === 'ntfc' ? 'set limit-uptime=1s' : 'remove';
  if (major === 7) {
    return (
      `:local hsprofile "${profileName}";\n` +
      `:foreach i in [ /ip hotspot user find where profile=$hsprofile ] do={\n` +
      `    :local comment [ /ip hotspot user get $i comment];\n` +
      `    :local name [ /ip hotspot user get $i name];\n` +
      `    :if ([:pic $comment 3] = "/" and [:pic $comment 6] = "/") do={\n` +
      `        :local gettime [:totime [:pic $comment 12 20]];\n` +
      `        :local getmonth [:pic $comment 0 3];\n` +
      `        :local getday [:tonum [:pic $comment 4 6]];\n` +
      `        :local getyear [:tonum [:pick $comment 7 11]];\n` +
      `        :local vmonths {"jan"=1;"feb"=2;"mar"=3;"apr"=4;"may"=5;"jun"=6;"jul"=7;"aug"=8;"sep"=9;"oct"=10;"nov"=11;"dec"=12};\n` +
      `        :local tmonths {"01"=1;"02"=2;"03"=3;"04"=4;"05"=5;"06"=6;"07"=7;"08"=8;"09"=9;"10"=10;"11"=11;"12"=12};\n` +
      `        :local date [ /system clock get date ];\n` +
      `        :local time [ /system clock get time ];\n` +
      `        :local dd [:tonum [:pick $date 8 10]];\n` +
      `        :local yy [:tonum [:pick $date 0 4]];\n` +
      `        :local month [:pick $date 5 7];\n` +
      `        :local mm (:$tmonths->$month);\n` +
      `        :local getmm (:$vmonths->$getmonth);\n` +
      `        :if (($getyear < $yy) or ($getyear <= $yy and $getmm < $mm) or ($getyear <= $yy and $getmm <= $mm and $getday < $dd) or ($getyear <= $yy and $getmm <= $mm and $getday <= $dd and $gettime <= $time)) do={\n` +
      `            [ /ip hotspot user ${mode} $i ];\n` +
      `            [ /ip hotspot active remove [find where user=$name] ];\n` +
      `            [ /ip hotspot cookie remove [find where user=$name] ];\n` +
      `        };\n` +
      `    };\n` +
      `};`
    );
  }
  const guardNotAlreadyExpired = mode === 'set limit-uptime=1s' ? ' and $limit != "1s" and $limit != "00:00:01"' : '';

  return (
    `:local dateint do={ :local montharray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :if ([:pick $d 4 5] = "-") do={ :local yr [:pick $d 0 4]; :local mo [:pick $d 5 7]; :local dy [:pick $d 8 10]; :return [:tonum ("$yr$mo$dy")]; } else={ :local days [:pick $d 4 6]; :local month [:pick $d 0 3]; :local year [:pick $d 7 11]; :local monthint ([:find $montharray $month]); :if ([:len $monthint] > 0) do={ :local mnum ($monthint + 1); :if ($mnum < 10) do={ :return [:tonum ("$year" . "0" . "$mnum$days")]; } else={ :return [:tonum ("$year$mnum$days")]; }; } else={ :return 0; }; }; }; :local timeint do={ :local hours [:tonum [:pick $t 0 2]]; :local minutes [:tonum [:pick $t 3 5]]; :local seconds [:tonum [:pick $t 6 8]]; :return ($hours * 3600 + $minutes * 60 + $seconds); }; :local date [/system clock get date]; :local time [/system clock get time]; :local today [$dateint d=$date]; :local curtime [$timeint t=$time]; :if ($today > 20200000) do={ :foreach i in=[/ip hotspot user find where profile="${profileName}"] do={ :local comment [/ip hotspot user get $i comment]; :local uname [/ip hotspot user get $i name]; :local limit [/ip hotspot user get $i limit-uptime]; :local expd 0; :local expt 0; :if ([:pick $comment 4 5] = "-") do={ :set expd [$dateint d=[:pick $comment 0 10]]; :set expt [$timeint t=[:pick $comment 11 19]]; } else={ :if ([:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/") do={ :set expd [$dateint d=[:pick $comment 0 11]]; :set expt [$timeint t=[:pick $comment 12 20]]; }; }; :if ($expd > 20200000) do={ :if (($expd < $today) or ($expd = $today and $expt <= $curtime)${guardNotAlreadyExpired}) do={ [ /ip hotspot user ${mode} $i ]; [ /ip hotspot active remove [find where user=$uname] ]; [ /ip hotspot cookie remove [find where user=$uname] ]; }; }; }; }`
  );
}

function createInitialRouterState(
  id: string,
  identity: string,
  version: string,
  major: 6 | 7,
  boardName: string,
  model: string
): RouterState {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  const months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
  const mon = months[now.getMonth()];
  const expDay = String(Math.min(28, now.getDate() + 1)).padStart(2, '0');

  // Both ROS6 and ROS7 on-login scripts stamp comments in exact same mmm/dd/yyyy HH:MM:SS format
  const activeExpComment = `${mon}/${expDay}/${y} 18:30:00`;
  const expiredNoticeComment = `jan/10/2025 09:15:00`;

  return {
    id,
    identity,
    version,
    major,
    boardName,
    model,
    cpuLoad: major === 7 ? '6' : '9',
    freeMemory: '214958080',
    totalMemory: '268435456',
    freeHdd: '12582912',
    totalHdd: '16777216',
    uptime: major === 7 ? '14d 06:42:19' : '42d 11:08:54',
    nextIdCounter: 100,
    profiles: [
      {
        '.id': '*0',
        name: 'default',
        'shared-users': '1',
        'rate-limit': '',
        'on-login': '',
        'address-pool': 'none',
        'status-autorefresh': '1m',
        'transparent-proxy': 'no',
      },
      {
        '.id': '*1',
        name: '1Day-5K',
        'shared-users': '1',
        'rate-limit': '5M/5M',
        'address-pool': 'hs-pool-1',
        'status-autorefresh': '1m',
        'transparent-proxy': 'no',
        'on-login': buildUniversalOnLogin('remc', '1d', '5000', '5000', 'Enable', major),
      },
      {
        '.id': '*2',
        name: '7Days-25K',
        'shared-users': '1',
        'rate-limit': '10M/10M',
        'address-pool': 'hs-pool-1',
        'status-autorefresh': '1m',
        'transparent-proxy': 'no',
        'on-login': buildUniversalOnLogin('ntfc', '7d', '25000', '25000', 'Enable', major),
      },
      {
        '.id': '*3',
        name: '30Days-VIP',
        'shared-users': '2',
        'rate-limit': '20M/20M',
        'address-pool': 'hs-pool-1',
        'status-autorefresh': '1m',
        'transparent-proxy': 'no',
        'on-login': buildUniversalOnLogin('remc', '30d', '100000', '100000', 'Disable', major),
      },
      {
        '.id': '*4',
        name: '3Hours-3K',
        'shared-users': '1',
        'rate-limit': '3M/3M',
        'address-pool': 'hs-pool-1',
        'status-autorefresh': '1m',
        'transparent-proxy': 'no',
        'on-login': buildUniversalOnLogin('ntfc', '3h', '3000', '3000', 'Disable', major),
      },
    ],
    schedulers: [
      {
        '.id': '*10',
        name: '1Day-5K',
        'start-date': major === 7 ? `${y}-${m}-${d}` : `${mon}/${d}/${y}`,
        'start-time': '01:15:00',
        interval: '00:02:00',
        'on-event': buildUniversalSchedulerOnEvent('1Day-5K', 'remc', major),
        comment: 'Monitor Profile 1Day-5K',
        disabled: 'false',
        'run-count': '14',
        'next-run': '00:01:45',
      },
      {
        '.id': '*11',
        name: '7Days-25K',
        'start-date': major === 7 ? `${y}-${m}-${d}` : `${mon}/${d}/${y}`,
        'start-time': '01:20:00',
        interval: '00:02:00',
        'on-event': buildUniversalSchedulerOnEvent('7Days-25K', 'ntfc', major),
        comment: 'Monitor Profile 7Days-25K',
        disabled: 'false',
        'run-count': '14',
        'next-run': '00:01:50',
      },
      {
        '.id': '*12',
        name: '30Days-VIP',
        'start-date': major === 7 ? `${y}-${m}-${d}` : `${mon}/${d}/${y}`,
        'start-time': '01:25:00',
        interval: '00:02:00',
        'on-event': buildUniversalSchedulerOnEvent('30Days-VIP', 'remc', major),
        comment: 'Monitor Profile 30Days-VIP',
        disabled: 'false',
        'run-count': '14',
        'next-run': '00:01:55',
      },
      {
        '.id': '*13',
        name: '3Hours-3K',
        'start-date': major === 7 ? `${y}-${m}-${d}` : `${mon}/${d}/${y}`,
        'start-time': '01:30:00',
        interval: '00:02:00',
        'on-event': buildUniversalSchedulerOnEvent('3Hours-3K', 'ntfc', major),
        comment: 'Monitor Profile 3Hours-3K',
        disabled: 'false',
        'run-count': '14',
        'next-run': '00:01:58',
      },
    ],
    users: [
      {
        '.id': '*1',
        name: 'default-trial',
        profile: 'default',
        uptime: '00:00:00',
        'bytes-in': '0',
        'bytes-out': '0',
        disabled: 'false',
        comment: '',
      },
      {
        '.id': '*2',
        name: 'vc8821',
        password: '8821',
        profile: '1Day-5K',
        uptime: '03:14:22',
        'limit-uptime': '1d',
        'bytes-in': '48291040',
        'bytes-out': '289104210',
        disabled: 'false',
        'mac-address': 'C8:5B:76:3A:11:04',
        comment: activeExpComment,
      },
      {
        '.id': '*3',
        name: 'vc9914',
        password: '9914',
        profile: '7Days-25K',
        uptime: '00:00:01',
        'limit-uptime': major === 7 ? '00:00:01' : '1s',
        'bytes-in': '19402100',
        'bytes-out': '84920112',
        disabled: 'false',
        'mac-address': 'D4:6E:0E:91:22:7B',
        comment: expiredNoticeComment,
      },
      {
        '.id': '*4',
        name: 'vc4410',
        password: '4410',
        profile: '1Day-5K',
        uptime: '00:00:00',
        'limit-uptime': '1d',
        'bytes-in': '0',
        'bytes-out': '0',
        disabled: 'false',
        comment: 'vc-412-03.26.26-KioskUtara [1d]',
      },
      {
        '.id': '*5',
        name: 'vc4411',
        password: '4411',
        profile: '1Day-5K',
        uptime: '00:00:00',
        'limit-uptime': '1d',
        'bytes-in': '0',
        'bytes-out': '0',
        disabled: 'false',
        comment: 'vc-412-03.26.26-KioskUtara [1d]',
      },
      {
        '.id': '*6',
        name: 'vip7701',
        password: '7701',
        profile: '30Days-VIP',
        uptime: '2d 05:19:40',
        'limit-uptime': '30d',
        'bytes-in': '512940100',
        'bytes-out': '2849102400',
        disabled: 'false',
        'mac-address': 'F0:18:98:4C:82:19',
        comment: activeExpComment,
      },
    ],
    active: [
      {
        '.id': '*21',
        server: 'hotspot1',
        user: 'vc8821',
        address: '192.168.50.104',
        'mac-address': 'C8:5B:76:3A:11:04',
        'login-by': 'http-chap',
        uptime: '03:14:22',
        'session-time-left': '20:45:38',
        'idle-time': '00:00:02',
        'bytes-in': '48291040',
        'bytes-out': '289104210',
        comment: activeExpComment,
      },
      {
        '.id': '*22',
        server: 'hotspot1',
        user: 'vip7701',
        address: '192.168.50.119',
        'mac-address': 'F0:18:98:4C:82:19',
        'login-by': 'cookie',
        uptime: '05:19:40',
        'session-time-left': '27d 18:40:20',
        'idle-time': '00:00:00',
        'bytes-in': '512940100',
        'bytes-out': '2849102400',
        comment: activeExpComment,
      },
    ],
    hosts: [
      {
        '.id': '*31',
        'mac-address': 'C8:5B:76:3A:11:04',
        address: '192.168.50.104',
        'to-address': '192.168.50.104',
        server: 'hotspot1',
        authorized: 'true',
        bypassed: 'false',
        comment: activeExpComment,
      },
      {
        '.id': '*32',
        'mac-address': 'F0:18:98:4C:82:19',
        address: '192.168.50.119',
        'to-address': '192.168.50.119',
        server: 'hotspot1',
        authorized: 'true',
        bypassed: 'false',
        comment: activeExpComment,
      },
    ],
    scripts: [
      // Pre-populated Selling Report scripts in BOTH ROS7 (YYYY-MM-DD) and ROS6 (mmm/dd/yyyy) formats!
      {
        '.id': '*41',
        name: `${y}-${m}-${d}-|-08:15:22-|-vc8821-|-5000-|-192.168.50.104-|-C8:5B:76:3A:11:04-|-1d-|-1Day-5K-|-vc-101`,
        owner: `${m}${y}`,
        source: `${y}-${m}-${d}`,
        comment: 'mikhmon',
      },
      {
        '.id': '*42',
        name: `${y}-${m}-${d}-|-09:42:10-|-vip7701-|-100000-|-192.168.50.119-|-F0:18:98:4C:82:19-|-30d-|-30Days-VIP-|-vc-102`,
        owner: `${m}${y}`,
        source: `${y}-${m}-${d}`,
        comment: 'mikhmon',
      },
      {
        '.id': '*43',
        name: `${mon}/${d}/${y}-|-10:05:49-|-vc9914-|-25000-|-192.168.50.112-|-D4:6E:0E:91:22:7B-|-7d-|-7Days-25K-|-vc-103`,
        owner: `${mon}${y}`,
        source: `${mon}/${d}/${y}`,
        comment: 'mikhmon',
      },
      {
        '.id': '*44',
        name: `jan/09/2025-|-09:15:00-|-vc5500-|-5000-|-192.168.50.145-|-A4:83:E7:2B:99:1C-|-1d-|-1Day-5K-|-${expiredNoticeComment}`,
        owner: `jan2025`,
        source: `jan/09/2025`,
        comment: 'mikhmon',
      },
    ],
    logs: [
      {
        '.id': '*51',
        time: '08:15:22',
        topics: 'hotspot,info,debug',
        message: `vc8821 (192.168.50.104): logged in`,
      },
      {
        '.id': '*52',
        time: '09:42:10',
        topics: 'hotspot,info,debug',
        message: `vip7701 (192.168.50.119): logged in`,
      },
      {
        '.id': '*53',
        time: '10:05:49',
        topics: 'hotspot,info,debug',
        message: `vc9914 (192.168.50.112): trying to log in by http-chap`,
      },
      {
        '.id': '*54',
        time: '10:06:01',
        topics: 'hotspot,info,debug',
        message: `vc9914 (192.168.50.112): login failed: reached limit-uptime (${major === 7 ? '00:00:01' : '1s'})`,
      },
    ],
    interfaces: [
      { '.id': '*1', name: 'ether1-ISP', type: 'ether', 'rx-byte': '4910294810', 'tx-byte': '892019402', running: 'true', disabled: 'false' },
      { '.id': '*2', name: 'ether2-Hotspot', type: 'ether', 'rx-byte': '892019402', 'tx-byte': '4910294810', running: 'true', disabled: 'false' },
      { '.id': '*3', name: 'wlan1-Hotspot', type: 'wlan', 'rx-byte': '391029400', 'tx-byte': '1920491020', running: 'true', disabled: 'false' },
    ],
    ipBindings: [
      { '.id': '*61', 'mac-address': '00:1A:2B:3C:4D:5E', address: '192.168.50.10', 'to-address': '192.168.50.10', server: 'all', type: 'bypassed', comment: 'AP-Lobby', disabled: 'false' },
    ],
    cookies: [
      { '.id': '*71', user: 'vip7701', 'mac-address': 'F0:18:98:4C:82:19', 'expires-in': '2d 18:40:20' },
    ],
    dhcpLeases: [
      { '.id': '*81', address: '192.168.50.104', 'mac-address': 'C8:5B:76:3A:11:04', 'host-name': 'Android-Device', server: 'dhcp-hs', status: 'bound' },
      { '.id': '*82', address: '192.168.50.119', 'mac-address': 'F0:18:98:4C:82:19', 'host-name': 'MacBook-Pro', server: 'dhcp-hs', status: 'bound' },
    ],
    queues: [
      { '.id': '*91', name: '<hotspot-vc8821>', target: '192.168.50.104/32', 'max-limit': '5000000/5000000', dynamic: 'true' },
    ],
    pools: [
      { '.id': '*1', name: 'hs-pool-1', ranges: '192.168.50.10-192.168.50.254' },
    ],
    hotspots: [
      { '.id': '*1', name: 'hotspot1', interface: 'ether2-Hotspot', 'address-pool': 'hs-pool-1', profile: 'hsprof1' },
    ],
    loggingRules: [
      { '.id': '*1', topics: 'hotspot,info,debug', prefix: '->', action: 'disk' },
    ],
    pppSecrets: [],
    pppProfiles: [{ '.id': '*1', name: 'default' }],
    pppActive: [],
  };
}

const routers: Record<string, RouterState> = {
  '127.0.0.1': createInitialRouterState(
    'MikroTik-ROS7',
    'MikroTik-ROS7-Core',
    '7.15.3 (stable)',
    7,
    'RB5009UG+S+',
    'RB5009UG+S+IN'
  ),
  '127.0.0.2': createInitialRouterState(
    'MikroTik-ROS6',
    'MikroTik-ROS6-Branch',
    '6.49.13 (long-term)',
    6,
    'hEX RB750Gr3',
    'RB750Gr3'
  ),
};

function parseCommandParams(words: string[]): {
  cmd: string;
  queries: Record<string, string>;
  attrs: Record<string, string>;
  proplist: string[] | null;
  countOnly: boolean;
} {
  const cmd = words[0] || '';
  const queries: Record<string, string> = {};
  const attrs: Record<string, string> = {};
  let proplist: string[] | null = null;
  let countOnly = false;

  for (let i = 1; i < words.length; i++) {
    const w = words[i];
    if (w.startsWith('?')) {
      const rest = w.slice(1);
      const eqIdx = rest.indexOf('=');
      if (eqIdx !== -1) {
        const k = rest.slice(0, eqIdx).replace(/^-/, '');
        const v = rest.slice(eqIdx + 1);
        queries[k] = v;
      }
    } else if (w.startsWith('=')) {
      const rest = w.slice(1);
      const eqIdx = rest.indexOf('=');
      if (eqIdx !== -1) {
        const k = rest.slice(0, eqIdx);
        const v = rest.slice(eqIdx + 1);
        if (k === '.proplist') {
          proplist = v.split(',').map((s) => s.trim());
        } else if (k === 'count-only') {
          countOnly = true;
        } else {
          attrs[k] = v;
        }
      } else if (rest === 'count-only') {
        countOnly = true;
      }
    }
  }
  return { cmd, queries, attrs, proplist, countOnly };
}

function filterCollection(
  items: Record<string, string>[],
  queries: Record<string, string>
): Record<string, string>[] {
  if (Object.keys(queries).length === 0) return items;
  return items.filter((item) => {
    for (const [k, v] of Object.entries(queries)) {
      if ((item[k] || '') !== v) return false;
    }
    return true;
  });
}

function handleCollectionCommand(
  socket: net.Socket,
  state: RouterState,
  collection: Record<string, string>[],
  action: string,
  queries: Record<string, string>,
  attrs: Record<string, string>,
  countOnly: boolean
) {
  if (action === 'print') {
    const matched = filterCollection(collection, queries);
    if (countOnly) {
      sendSentence(socket, ['!done', `=ret=${matched.length}`]);
    } else {
      sendRowsAndDone(socket, matched);
    }
    return;
  }

  if (action === 'add') {
    const newId = `*${++state.nextIdCounter}`;
    const newItem: Record<string, string> = { '.id': newId, ...attrs };
    if (newItem.disabled === 'no') newItem.disabled = 'false';
    if (newItem.disabled === 'yes') newItem.disabled = 'true';
    collection.push(newItem);
    sendSentence(socket, ['!done', `=ret=${newId}`]);
    return;
  }

  if (action === 'set') {
    const targetId = attrs['.id'] || attrs['numbers'];
    for (const item of collection) {
      if (item['.id'] === targetId || item.name === targetId) {
        for (const [k, v] of Object.entries(attrs)) {
          if (k === '.id' || k === 'numbers') continue;
          if (k === 'disabled') {
            item.disabled = v === 'yes' || v === 'true' ? 'true' : 'false';
          } else {
            item[k] = v;
          }
        }
      }
    }
    sendSentence(socket, ['!done']);
    return;
  }

  if (action === 'remove') {
    const targetId = attrs['.id'] || attrs['numbers'];
    const idx = collection.findIndex((item) => item['.id'] === targetId || item.name === targetId);
    if (idx !== -1) {
      collection.splice(idx, 1);
    }
    sendSentence(socket, ['!done']);
    return;
  }

  if (action === 'reset-counters') {
    const targetId = attrs['.id'] || attrs['numbers'];
    for (const item of collection) {
      if (!targetId || item['.id'] === targetId) {
        item.uptime = '00:00:00';
        item['bytes-in'] = '0';
        item['bytes-out'] = '0';
      }
    }
    sendSentence(socket, ['!done']);
    return;
  }

  sendSentence(socket, ['!done']);
}

function parseClockDateParts(dateStr: string): { year: number; month: number; day: number } | null {
  const s = (dateStr || '').trim().toLowerCase();
  const vmonths: Record<string, number> = {
    jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6,
    jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12,
  };
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (iso) {
    return { year: parseInt(iso[1], 10), month: parseInt(iso[2], 10), day: parseInt(iso[3], 10) };
  }
  const leg = s.match(/^([a-z]{3})\/(\d{2})\/(\d{4})/);
  if (leg && vmonths[leg[1]]) {
    return { year: parseInt(leg[3], 10), month: vmonths[leg[1]], day: parseInt(leg[2], 10) };
  }
  return null;
}

function parseClockTimeSeconds(timeStr: string): number | null {
  const m = (timeStr || '').trim().match(/^(\d{2}):(\d{2}):(\d{2})/);
  if (!m) return null;
  return parseInt(m[1], 10) * 3600 + parseInt(m[2], 10) * 60 + parseInt(m[3], 10);
}

function executeRouterSchedulerSweep(state: RouterState) {
  const curDateStr = getFormattedClockDate(state.major, state);
  const curTimeStr = getFormattedClockTime(state);
  const curDate = parseClockDateParts(curDateStr);
  const curTimeSec = parseClockTimeSeconds(curTimeStr);
  if (!curDate || curTimeSec === null) return;

  for (const sch of state.schedulers) {
    if (sch.disabled === 'true' || sch.disabled === 'yes') continue;
    const onEvent = sch['on-event'] || '';
    if (!onEvent.trim()) continue;

    sch['run-count'] = String((parseInt(sch['run-count'] || '0', 10) || 0) + 1);

    // Detect if ROS7 scheduler script has uncast string gettime compared with time
    if (
      state.major === 7 &&
      onEvent.includes(':local gettime [:pic $comment 12 20]') &&
      !onEvent.includes('[:totime')
    ) {
      state.logs.push({
        '.id': `*${++state.nextIdCounter}`,
        time: curTimeStr,
        topics: 'script,error',
        message: `executing script from scheduler (${sch.name}) failed: cannot compare if string is less than time`,
      });
      continue;
    }

    const profMatch =
      onEvent.match(/:local\s+hsprofile\s+"([^"]+)"/) ||
      onEvent.match(/profile="([^"]+)"/);
    if (!profMatch) continue;
    const targetProfile = profMatch[1];
    const isNoticeMode = onEvent.includes('set limit-uptime=1s');

    const toRemoveIds: string[] = [];
    for (const u of state.users) {
      if (u.profile !== targetProfile) continue;
      const comment = (u.comment || '').trim();
      if (comment.length < 20 || comment[3] !== '/' || comment[6] !== '/') continue;

      const uDate = parseClockDateParts(comment.slice(0, 11));
      const uTimeSec = parseClockTimeSeconds(comment.slice(12, 20));
      if (!uDate || uTimeSec === null) continue;

      const isExpired =
        uDate.year < curDate.year ||
        (uDate.year <= curDate.year && uDate.month < curDate.month) ||
        (uDate.year <= curDate.year && uDate.month <= curDate.month && uDate.day < curDate.day) ||
        (uDate.year <= curDate.year &&
          uDate.month <= curDate.month &&
          uDate.day <= curDate.day &&
          uTimeSec <= curTimeSec);

      if (!isExpired) continue;

      const uname = u.name;
      if (isNoticeMode) {
        const expLimit = state.major === 7 ? '00:00:01' : '1s';
        if (u['limit-uptime'] !== '1s' && u['limit-uptime'] !== '00:00:01') {
          u['limit-uptime'] = expLimit;
          state.logs.push({
            '.id': `*${++state.nextIdCounter}`,
            time: curTimeStr,
            topics: 'hotspot,info,debug',
            message: `scheduler ${sch.name}: user ${uname} expired (${comment}), limit-uptime set to ${expLimit}`,
          });
        }
      } else {
        toRemoveIds.push(u['.id']);
        state.logs.push({
          '.id': `*${++state.nextIdCounter}`,
          time: curTimeStr,
          topics: 'system,info,account',
          message: `user ${uname} removed by scheduler:${sch.name} (expired ${comment} <= ${curDateStr} ${curTimeStr})`,
        });
      }

      // Remove active sessions and cookies for the expired user
      for (let idx = state.active.length - 1; idx >= 0; idx--) {
        if (state.active[idx].user === uname) {
          state.active.splice(idx, 1);
        }
      }
      for (let idx = state.cookies.length - 1; idx >= 0; idx--) {
        if (state.cookies[idx].user === uname) {
          state.cookies.splice(idx, 1);
        }
      }
    }

    if (toRemoveIds.length > 0) {
      state.users = state.users.filter((u) => !toRemoveIds.includes(u['.id']));
    }
  }
}

function handleRouterSentence(socket: net.Socket, state: RouterState, words: string[]) {
  if (words.length === 0) return;
  const { cmd, queries, attrs, countOnly } = parseCommandParams(words);

  if (cmd === '/login') {
    // Support both ROS 6.43+ direct login and challenge-response fallback
    sendSentence(socket, ['!done']);
    return;
  }

  if (cmd === '/system/identity/print') {
    sendRowsAndDone(socket, [{ name: state.identity }]);
    return;
  }

  if (cmd === '/system/clock/print') {
    sendRowsAndDone(socket, [
      {
        time: getFormattedClockTime(state),
        date: getFormattedClockDate(state.major, state),
        'time-zone-autodetect': 'true',
        'time-zone-name': 'Asia/Jakarta',
        'gmt-offset': '+07:00',
      },
    ]);
    return;
  }

  if (cmd === '/system/clock/set') {
    if (attrs.date !== undefined) {
      state.customClockDate = attrs.date === '' ? undefined : attrs.date;
    }
    if (attrs.time !== undefined) {
      state.customClockTime = attrs.time === '' ? undefined : attrs.time;
    }
    executeRouterSchedulerSweep(state);
    sendSentence(socket, ['!done']);
    return;
  }

  if (cmd === '/system/resource/print') {
    sendRowsAndDone(socket, [
      {
        uptime: state.uptime,
        version: state.version,
        'build-time': state.major === 7 ? '2024-07-24 11:20:00' : 'May/14/2024 09:11:00',
        'free-memory': state.freeMemory,
        'total-memory': state.totalMemory,
        cpu: 'ARM64',
        'cpu-count': '4',
        'cpu-frequency': '1400',
        'cpu-load': state.cpuLoad,
        'free-hdd-space': state.freeHdd,
        'total-hdd-space': state.totalHdd,
        'architecture-name': state.major === 7 ? 'arm64' : 'mmips',
        'board-name': state.boardName,
        platform: 'MikroTik',
      },
    ]);
    return;
  }

  if (cmd === '/system/routerboard/print') {
    sendRowsAndDone(socket, [
      {
        routerboard: 'true',
        model: state.model,
        'serial-number': state.major === 7 ? 'HE8092K114B' : '9A2108F991C',
        'firmware-type': '70x0',
        'factory-firmware': state.major === 7 ? '7.8' : '6.45',
        'current-firmware': state.major === 7 ? '7.15.3' : '6.49.13',
        'upgrade-firmware': state.major === 7 ? '7.15.3' : '6.49.13',
      },
    ]);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/user/profile/')) {
    const action = cmd.replace('/ip/hotspot/user/profile/', '');
    handleCollectionCommand(socket, state, state.profiles, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/user/')) {
    const action = cmd.replace('/ip/hotspot/user/', '');
    handleCollectionCommand(socket, state, state.users, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/active/')) {
    const action = cmd.replace('/ip/hotspot/active/', '');
    handleCollectionCommand(socket, state, state.active, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/host/')) {
    const action = cmd.replace('/ip/hotspot/host/', '');
    handleCollectionCommand(socket, state, state.hosts, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/ip-binding/')) {
    const action = cmd.replace('/ip/hotspot/ip-binding/', '');
    handleCollectionCommand(socket, state, state.ipBindings, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/cookie/')) {
    const action = cmd.replace('/ip/hotspot/cookie/', '');
    handleCollectionCommand(socket, state, state.cookies, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/hotspot/')) {
    const action = cmd.replace('/ip/hotspot/', '');
    handleCollectionCommand(socket, state, state.hotspots, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/system/scheduler/')) {
    const action = cmd.replace('/system/scheduler/', '');
    handleCollectionCommand(socket, state, state.schedulers, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/system/script/')) {
    const action = cmd.replace('/system/script/', '');
    handleCollectionCommand(socket, state, state.scripts, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/system/logging/')) {
    const action = cmd.replace('/system/logging/', '');
    handleCollectionCommand(socket, state, state.loggingRules, action, queries, attrs, countOnly);
    return;
  }

  if (cmd === '/log/print') {
    sendRowsAndDone(socket, state.logs);
    return;
  }

  if (cmd.startsWith('/interface/monitor-traffic')) {
    const rxBps = String(1840000 + Math.floor(Math.random() * 450000));
    const txBps = String(6420000 + Math.floor(Math.random() * 950000));
    sendRowsAndDone(socket, [
      {
        name: attrs.interface || 'ether1-ISP',
        'rx-bits-per-second': rxBps,
        'tx-bits-per-second': txBps,
        'rx-packets-per-second': '340',
        'tx-packets-per-second': '790',
      },
    ]);
    return;
  }

  if (cmd.startsWith('/interface/')) {
    const action = cmd.replace('/interface/', '');
    handleCollectionCommand(socket, state, state.interfaces, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/dhcp-server/lease/')) {
    const action = cmd.replace('/ip/dhcp-server/lease/', '');
    handleCollectionCommand(socket, state, state.dhcpLeases, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/queue/simple/')) {
    const action = cmd.replace('/queue/simple/', '');
    handleCollectionCommand(socket, state, state.queues, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ip/pool/')) {
    const action = cmd.replace('/ip/pool/', '');
    handleCollectionCommand(socket, state, state.pools, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ppp/secret/')) {
    const action = cmd.replace('/ppp/secret/', '');
    handleCollectionCommand(socket, state, state.pppSecrets, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ppp/profile/')) {
    const action = cmd.replace('/ppp/profile/', '');
    handleCollectionCommand(socket, state, state.pppProfiles, action, queries, attrs, countOnly);
    return;
  }

  if (cmd.startsWith('/ppp/active/')) {
    const action = cmd.replace('/ppp/active/', '');
    handleCollectionCommand(socket, state, state.pppActive, action, queries, attrs, countOnly);
    return;
  }

  // Fallback for any other command
  sendSentence(socket, ['!done']);
}

function startRouterOsTcpServer(hostIp: string, state: RouterState) {
  const server = net.createServer((socket) => {
    let buffer = Buffer.alloc(0);
    let currentWords: string[] = [];

    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk]);

      while (buffer.length > 0) {
        const b0 = buffer[0];
        let wordLen = 0;
        let headerBytes = 1;

        if ((b0 & 0x80) === 0x00) {
          wordLen = b0;
          headerBytes = 1;
        } else if ((b0 & 0xc0) === 0x80) {
          if (buffer.length < 2) return;
          wordLen = ((b0 & ~0xc0) << 8) | buffer[1];
          headerBytes = 2;
        } else if ((b0 & 0xe0) === 0xc0) {
          if (buffer.length < 3) return;
          wordLen = ((b0 & ~0xe0) << 16) | (buffer[1] << 8) | buffer[2];
          headerBytes = 3;
        } else if ((b0 & 0xf0) === 0xe0) {
          if (buffer.length < 4) return;
          wordLen = ((b0 & ~0xf0) << 24) | (buffer[1] << 16) | (buffer[2] << 8) | buffer[3];
          headerBytes = 4;
        } else {
          if (buffer.length < 5) return;
          wordLen = (buffer[1] << 24) | (buffer[2] << 16) | (buffer[3] << 8) | buffer[4];
          headerBytes = 5;
        }

        if (buffer.length < headerBytes + wordLen) {
          return; // wait for more bytes
        }

        const word = buffer.slice(headerBytes, headerBytes + wordLen).toString('utf8');
        buffer = buffer.slice(headerBytes + wordLen);

        if (wordLen === 0) {
          const sentence = currentWords;
          currentWords = [];
          if (sentence.length > 0) {
            handleRouterSentence(socket, state, sentence);
          }
        } else {
          currentWords.push(word);
        }
      }
    });

    socket.on('error', () => {
      // ignore client disconnect errors
    });
  });

  server.on('error', (err: NodeJS.ErrnoException) => {
    console.error(`[RouterOS Binary API] Warning on ${hostIp}:8728:`, err.message);
  });

  server.listen(8728, hostIp, () => {
    console.log(`[RouterOS Binary API] Listening on ${hostIp}:8728 (${state.identity} - RouterOS ${state.version})`);
  });
}

// ============================================================================
// 3. START ORIGINAL MIKHMON v3 PHP APPLICATION + PORT 3000 REVERSE PROXY
// ============================================================================

const PHP_PORT = 8081;
const PORT = 3000;
const MIKHMON_DOCROOT = path.resolve(process.cwd(), 'mikhmonv3-upstream');

let phpProcess: ChildProcess | null = null;
let isInstallingPhp = false;
let isShuttingDown = false;
let isPhpRestarting = false;
let consecutivePhpFailures = 0;
let phpWatchdogTimer: NodeJS.Timeout | null = null;

function hasPhpBinary(): boolean {
  try {
    execSync('which php', { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

function cleanupOrphanedPhpPort() {
  try {
    execSync(`fuser -k ${PHP_PORT}/tcp`, { stdio: 'ignore' });
  } catch {
    // Ignore if fuser is unavailable or no process holds the port
  }
}

function ensurePhpAndStartServer() {
  if (hasPhpBinary()) {
    startPhpServer();
    return;
  }
  if (isInstallingPhp) return;
  isInstallingPhp = true;
  console.log('[PHP Server] php-cli not found, auto-installing php-cli...');
  const installer = spawn(
    'sh',
    [
      '-c',
      'DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" php-cli',
    ],
    { stdio: 'inherit' }
  );
  installer.on('error', (err) => {
    console.error('[PHP Server] Installer error:', err.message);
    isInstallingPhp = false;
  });
  installer.on('exit', () => {
    isInstallingPhp = false;
    startPhpServer();
  });
}

function startPhpServer() {
  if (isShuttingDown) return;
  if (!hasPhpBinary()) {
    ensurePhpAndStartServer();
    return;
  }

  isPhpRestarting = false;
  consecutivePhpFailures = 0;

  if (!phpProcess) {
    cleanupOrphanedPhpPort();
  }

  const workers = process.env.PHP_CLI_SERVER_WORKERS || '12';
  const child = spawn(
    'php',
    [
      '-d',
      'display_errors=1',
      '-d',
      'error_reporting=E_ALL',
      '-d',
      'log_errors=1',
      '-d',
      'max_execution_time=600',
      '-d',
      'default_socket_timeout=35',
      '-S',
      `127.0.0.1:${PHP_PORT}`,
      '-t',
      MIKHMON_DOCROOT,
    ],
    {
      cwd: MIKHMON_DOCROOT,
      env: {
        ...process.env,
        PHP_CLI_SERVER_WORKERS: workers,
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    }
  );

  phpProcess = child;

  // CRITICAL: Always drain stdout and stderr pipes so the 64 KB Linux kernel pipe buffer
  // never fills up and deadlocks PHP on write(2) during access logging!
  if (child.stdout) {
    child.stdout.on('data', () => {
      // Drain stdout continuously
    });
  }
  if (child.stderr) {
    child.stderr.on('data', (chunk: Buffer) => {
      const msg = chunk.toString('utf8');
      if (msg.includes('Fatal error') || msg.includes('Parse error') || msg.includes('Failed to listen')) {
        console.error(`[PHP Server] ${msg.trim()}`);
      }
    });
  }

  child.on('error', (err) => {
    console.error(`[PHP Server] spawn error: ${err.message}`);
  });

  child.on('exit', (code, signal) => {
    if (phpProcess === child) {
      phpProcess = null;
    }
    if (isShuttingDown || isPhpRestarting) return;
    isPhpRestarting = true;
    console.log(`[PHP Server] exited (code=${code}, signal=${signal}), restarting in 500ms...`);
    setTimeout(startPhpServer, 500);
  });
}

function recyclePhpServer(reason: string) {
  if (isShuttingDown || isPhpRestarting) return;
  isPhpRestarting = true;
  console.warn(`[PHP Watchdog] Recycling PHP backend (${reason})...`);
  if (phpProcess) {
    try {
      phpProcess.kill('SIGKILL');
    } catch {
      // Ignore kill errors
    }
    phpProcess = null;
  }
  cleanupOrphanedPhpPort();
  setTimeout(startPhpServer, 500);
}

function probePhpHealth(timeoutMs = 4000): Promise<boolean> {
  return new Promise((resolve) => {
    const req = http.get(
      {
        hostname: '127.0.0.1',
        port: PHP_PORT,
        path: '/status/server_metrics.php?health=1',
        timeout: timeoutMs,
      },
      (res) => {
        res.resume();
        resolve((res.statusCode || 500) >= 200 && (res.statusCode || 500) < 400);
      }
    );
    req.on('timeout', () => {
      req.destroy();
      resolve(false);
    });
    req.on('error', () => {
      resolve(false);
    });
  });
}

function startPhpWatchdog() {
  if (phpWatchdogTimer) clearInterval(phpWatchdogTimer);
  phpWatchdogTimer = setInterval(async () => {
    if (isShuttingDown || isInstallingPhp || isPhpRestarting) return;
    const healthy = await probePhpHealth(4000);
    if (healthy) {
      consecutivePhpFailures = 0;
    } else {
      consecutivePhpFailures++;
      console.warn(`[PHP Watchdog] Health probe failed (${consecutivePhpFailures}/5) on 127.0.0.1:${PHP_PORT}`);
      if (consecutivePhpFailures >= 5) {
        consecutivePhpFailures = 0;
        recyclePhpServer('5 consecutive health check failures');
      }
    }
  }, 15000);
}

function shutdownCleanly(signal: string) {
  if (isShuttingDown) return;
  isShuttingDown = true;
  console.log(`[Mikhmon v3 Server] Received ${signal}, shutting down cleanly...`);
  if (phpWatchdogTimer) {
    clearInterval(phpWatchdogTimer);
    phpWatchdogTimer = null;
  }
  if (phpProcess) {
    try {
      phpProcess.kill('SIGTERM');
    } catch {
      // Ignore
    }
    phpProcess = null;
  }
  cleanupOrphanedPhpPort();
  process.exit(0);
}

process.on('SIGTERM', () => shutdownCleanly('SIGTERM'));
process.on('SIGINT', () => shutdownCleanly('SIGINT'));
process.on('exit', () => {
  if (phpProcess) {
    try {
      phpProcess.kill('SIGKILL');
    } catch {
      // Ignore
    }
  }
});

startRouterOsTcpServer('127.0.0.1', routers['127.0.0.1']);
startRouterOsTcpServer('127.0.0.2', routers['127.0.0.2']);
setInterval(() => {
  executeRouterSchedulerSweep(routers['127.0.0.1']);
  executeRouterSchedulerSweep(routers['127.0.0.2']);
}, 2000);
setInterval(() => {
  if (isShuttingDown || isInstallingPhp) return;
  execFile(
    'php',
    [
      '-r',
      'require_once "mikhmonv3-upstream/include/radius_engine.php"; foreach (loadRadiusNasList() as $n) { radiusRunExpiryMonitor($n["nas_ip"]); }',
    ],
    { timeout: 4000 },
    () => {
      // Background RADIUS expiry monitor completed
    }
  );
}, 10000);
ensurePhpAndStartServer();
startPhpWatchdog();

const proxyServer = http.createServer(async (req, res) => {
  if (req.url === '/__health' || req.url?.startsWith('/__health?')) {
    const phpUp = await probePhpHealth(2500);
    const statusCode = phpUp ? 200 : 503;
    res.writeHead(statusCode, {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store, no-cache, must-revalidate, max-age=0',
    });
    res.end(
      JSON.stringify({
        status: phpUp ? 'ok' : 'degraded',
        node: 'up',
        php: phpUp ? 'up' : 'down',
        uptimeSec: Math.floor(process.uptime()),
      })
    );
    return;
  }

  // Track slow PHP requests without logging router/session query values.
  const requestStartedAt = Date.now();
  const parsedRequestUrl = new URL(req.url || '/', 'http://localhost');
  const requestAction = parsedRequestUrl.searchParams.get('hotspot-user');
  const routeLabel = parsedRequestUrl.pathname +
    (requestAction ? '?hotspot-user=' + requestAction : '');
  // Both the voucher-generation POST and its follow-up GET result page can be
  // slow on high-latency routers. Give both requests enough time; otherwise the
  // router may have created the users while the browser receives a timeout page.
  const isVoucherGenerationPage =
    requestAction === 'generate' || parsedRequestUrl.searchParams.get('voucher-result') === '1';
  const isVoucherGenerationSubmit = req.method === 'POST' && isVoucherGenerationPage;
  const upstreamTimeoutMs = isVoucherGenerationPage ? 630000 : 60000;
  res.on('finish', () => {
    const durationMs = Date.now() - requestStartedAt;
    if (durationMs >= 10000) {
      console.warn(`[PHP Proxy] Slow request: ${req.method} ${routeLabel} status=${res.statusCode} durationMs=${durationMs}`);
    }
  });

  const isHttpsForwarded =
    req.headers['x-forwarded-proto'] === 'https' ||
    (typeof req.headers.referer === 'string' && req.headers.referer.startsWith('https://'));

  const options: http.RequestOptions = {
    hostname: '127.0.0.1',
    port: PHP_PORT,
    path: req.url || '/',
    method: req.method,
    headers: {
      ...req.headers,
      host: req.headers.host || `localhost:${PORT}`,
    },
  };

  const proxyReq = http.request(options, (proxyRes) => {
    const headers = { ...proxyRes.headers };

    // Rewrite Set-Cookie in HTTPS iframe environment so PHPSESSID works across origins
    if (isHttpsForwarded && headers['set-cookie']) {
      const rawCookies = Array.isArray(headers['set-cookie'])
        ? headers['set-cookie']
        : [headers['set-cookie']];
      headers['set-cookie'] = rawCookies.map((cookie) => {
        let updated = cookie.replace(/;\s*SameSite=[^;]*/gi, '').replace(/;\s*Secure/gi, '');
        return `${updated}; SameSite=None; Secure`;
      });
    }

    if (!res.headersSent) {
      res.writeHead(proxyRes.statusCode || 200, headers);
    }
    proxyRes.pipe(res, { end: true });
  });

  proxyReq.setTimeout(upstreamTimeoutMs, () => {
    const timeoutSeconds = Math.round(upstreamTimeoutMs / 1000);
    console.error(`[PHP Proxy] Upstream timeout after ${timeoutSeconds}s: ${req.method} ${routeLabel}`);
    proxyReq.destroy(new Error(`Upstream PHP request timed out after ${timeoutSeconds}s`));
  });

  proxyReq.on('error', (err) => {
    if (!res.headersSent) {
      const isTimeout = err.message.includes('timed out');
      res.writeHead(isTimeout ? 504 : 502, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end(
        isTimeout
          ? (isVoucherGenerationPage
              ? (isVoucherGenerationSubmit
                  ? 'NextGan WiFi Solution: Voucher generation response timed out. Some or all vouchers may already exist. Check Router Hotspot Users before retrying to avoid duplicates.'
                  : 'NextGan WiFi Solution: Voucher generation may have completed, but the result page timed out. Check Router Hotspot Users before retrying to avoid duplicates.')
              : 'NextGan WiFi Solution: Backend request timed out. Verify RouterOS API port 8728 and PHP backend health.')
          : `NextGan WiFi Solution: PHP Engine warming up... (${err.message})`
      );
    } else {
      res.end();
    }
  });

  req.pipe(proxyReq, { end: true });
});

proxyServer.on('error', (err: NodeJS.ErrnoException) => {
  console.error(`[Mikhmon v3 Server] Proxy error:`, err.message);
});

proxyServer.listen(PORT, '0.0.0.0', () => {
  console.log(`[Mikhmon v3 Server] Original PHP Application running at http://0.0.0.0:${PORT}`);
});
