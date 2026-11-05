import React, { useState } from 'react';
import {
  type HotspotProfile,
  type ResellerAccount,
  type ResellerPermissions,
  type RouterSessionConfig,
} from '../utils/mikhmonEngine';

interface ResellerManagementProps {
  resellers: ResellerAccount[];
  routers: RouterSessionConfig[];
  profiles: HotspotProfile[];
  actorRole: 'admin' | 'reseller';
  activeResellerId: string | null;
  onSelectActor: (role: 'admin' | 'reseller', resellerId: string | null) => void;
  onSaveReseller: (reseller: ResellerAccount) => void;
  onToggleResellerStatus: (resellerId: string) => void;
}

const PERMISSION_LABELS: { key: keyof ResellerPermissions; label: string }[] = [
  { key: 'generateVoucher', label: 'Generate Batch Vouchers' },
  { key: 'addSingleUser', label: 'Add Single Hotspot User' },
  { key: 'editUser', label: 'Edit Existing User' },
  { key: 'deleteUser', label: 'Delete Hotspot Users' },
  { key: 'manageProfiles', label: 'Create/Edit User Profiles' },
  { key: 'viewReports', label: 'View Selling Reports' },
  { key: 'deleteReports', label: 'Delete Sales Records' },
  { key: 'disconnectActive', label: 'Disconnect Active Sessions' },
  { key: 'routerMigration', label: 'Run Router Migration' },
];

export const ResellerManagementView: React.FC<ResellerManagementProps> = ({
  resellers,
  routers,
  profiles,
  actorRole,
  activeResellerId,
  onSelectActor,
  onSaveReseller,
  onToggleResellerStatus,
}) => {
  const [editingId, setEditingId] = useState<string | null>(resellers[0]?.id || null);
  const [newUsername, setNewUsername] = useState('');
  const [newFullName, setNewFullName] = useState('');
  const [newWallet, setNewWallet] = useState('300000');
  const [newMaxQty, setNewMaxQty] = useState('50');
  const [newRouterId, setNewRouterId] = useState(routers[0]?.id || '');
  const [feedback, setFeedback] = useState<string | null>(null);

  const selectedReseller = resellers.find((r) => r.id === editingId) || resellers[0];
  const uniqueProfileNames = Array.from(new Set(profiles.map((p) => p.name)));

  const handleTogglePermission = (permKey: keyof ResellerPermissions) => {
    if (!selectedReseller || actorRole !== 'admin') return;
    const updated: ResellerAccount = {
      ...selectedReseller,
      permissions: {
        ...selectedReseller.permissions,
        [permKey]: !selectedReseller.permissions[permKey],
      },
    };
    onSaveReseller(updated);
    setFeedback(
      `Updated permission "${permKey}" for ${selectedReseller.username}.`
    );
  };

  const handleToggleRouterAssignment = (routerId: string) => {
    if (!selectedReseller || actorRole !== 'admin') return;
    const exists = selectedReseller.assignedRouters.includes(routerId);
    const nextRouters = exists
      ? selectedReseller.assignedRouters.filter((r) => r !== routerId)
      : [...selectedReseller.assignedRouters, routerId];
    onSaveReseller({
      ...selectedReseller,
      assignedRouters: nextRouters,
    });
    setFeedback(
      `${exists ? 'Revoked' : 'Assigned'} router "${routerId}" for ${selectedReseller.username}.`
    );
  };

  const handleToggleProfileAssignment = (profileName: string) => {
    if (!selectedReseller || actorRole !== 'admin') return;
    const exists = selectedReseller.allowedProfiles.includes(profileName);
    const nextProfiles = exists
      ? selectedReseller.allowedProfiles.filter((p) => p !== profileName)
      : [...selectedReseller.allowedProfiles, profileName];
    onSaveReseller({
      ...selectedReseller,
      allowedProfiles: nextProfiles,
    });
    setFeedback(
      `${exists ? 'Removed' : 'Added'} profile "${profileName}" for ${selectedReseller.username}.`
    );
  };

  const handleCreateReseller = (e: React.FormEvent) => {
    e.preventDefault();
    if (actorRole !== 'admin') return;
    const cleanUser = newUsername.trim().toLowerCase().replace(/\s+/g, '_');
    if (!cleanUser || !newFullName.trim()) {
      setFeedback('Please enter both username and full name for the new reseller.');
      return;
    }
    const created: ResellerAccount = {
      id: `res_${Date.now()}`,
      username: cleanUser,
      fullName: newFullName.trim(),
      status: 'active',
      assignedRouters: newRouterId ? [newRouterId] : [routers[0].id],
      allowedProfiles: ['Voucher-3Jam', 'Voucher-1Hari'],
      maxVoucherBatchQty: Math.max(1, parseInt(newMaxQty, 10) || 50),
      walletBalance: Math.max(0, parseInt(newWallet, 10) || 0),
      totalVouchersGenerated: 0,
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
    onSaveReseller(created);
    setEditingId(created.id);
    setNewUsername('');
    setNewFullName('');
    setFeedback(`Created reseller account "${created.username}" assigned to ${created.assignedRouters.join(', ')}.`);
  };

  return (
    <div className="space-y-4">
      {/* Role Impersonation & Enforcement Banner */}
      <div className="bg-[#272b30] border border-[#3a4149] rounded p-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-white">
            Reseller Management, Router Assignments & Granular RBAC Matrix
          </h3>
          <p className="text-xs text-slate-300 mt-0.5">
            Switch active operator session below to test reseller router isolation, voucher batch quotas, and unauthorized operation blocking live.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => onSelectActor('admin', null)}
            className={`px-3 py-1.5 text-xs font-semibold rounded transition-colors ${
              actorRole === 'admin'
                ? 'bg-[#20a8d8] text-white'
                : 'bg-[#1e2125] text-slate-300 hover:bg-[#32383e]'
            }`}
          >
            Session: Super Admin (mikhmon)
          </button>
          {resellers.map((r) => (
            <button
              key={r.id}
              type="button"
              onClick={() => onSelectActor('reseller', r.id)}
              className={`px-3 py-1.5 text-xs font-semibold rounded transition-colors ${
                actorRole === 'reseller' && activeResellerId === r.id
                  ? 'bg-[#4dbd74] text-white'
                  : 'bg-[#1e2125] text-slate-300 hover:bg-[#32383e]'
              }`}
            >
              Test as Reseller: {r.username}
            </button>
          ))}
        </div>
      </div>

      {feedback && (
        <div className="bg-cyan-950/60 border border-cyan-600 text-cyan-200 px-3 py-2 rounded text-xs">
          {feedback}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
        {/* Left column: Reseller Accounts & Create Form */}
        <div className="lg:col-span-5 space-y-4">
          <div className="bg-[#272b30] border border-[#3a4149] rounded overflow-hidden">
            <div className="px-4 py-2.5 border-b border-[#3a4149] bg-[#1e2125]">
              <h4 className="text-xs font-semibold text-white">
                Configured Reseller Accounts ({resellers.length})
              </h4>
            </div>
            <div className="divide-y divide-[#3a4149]">
              {resellers.map((r) => (
                <div
                  key={r.id}
                  onClick={() => setEditingId(r.id)}
                  className={`p-3 cursor-pointer transition-colors ${
                    selectedReseller?.id === r.id ? 'bg-[#32383e]' : 'hover:bg-[#2e3338]'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-xs font-bold text-white">{r.username}</span>
                      <span className="text-xs text-slate-400"> · {r.fullName}</span>
                    </div>
                    <span
                      className={`text-[11px] font-mono font-semibold ${
                        r.status === 'active' ? 'text-emerald-400' : 'text-red-400'
                      }`}
                    >
                      {r.status.toUpperCase()}
                    </span>
                  </div>
                  <div className="mt-1.5 flex flex-wrap items-center justify-between text-[11px] text-slate-300 font-mono tabular-nums">
                    <span>Routers: {r.assignedRouters.join(', ') || 'None'}</span>
                    <span>Balance: Rp {r.walletBalance.toLocaleString('id-ID')}</span>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Add New Reseller Form */}
          <form
            onSubmit={handleCreateReseller}
            className="bg-[#272b30] border border-[#3a4149] rounded p-4 space-y-3"
          >
            <h4 className="text-xs font-semibold text-white">Add New Reseller Account</h4>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
              <div>
                <label className="block text-[11px] text-slate-300 mb-1">Reseller Username</label>
                <input
                  type="text"
                  value={newUsername}
                  onChange={(e) => setNewUsername(e.target.value)}
                  disabled={actorRole !== 'admin'}
                  placeholder="e.g. outlet_barat"
                  className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1.5 text-xs text-white"
                />
              </div>
              <div>
                <label className="block text-[11px] text-slate-300 mb-1">Full / Outlet Name</label>
                <input
                  type="text"
                  value={newFullName}
                  onChange={(e) => setNewFullName(e.target.value)}
                  disabled={actorRole !== 'admin'}
                  placeholder="e.g. Outlet Barat Cell"
                  className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1.5 text-xs text-white"
                />
              </div>
              <div>
                <label className="block text-[11px] text-slate-300 mb-1">Initial Wallet (Rp)</label>
                <input
                  type="number"
                  value={newWallet}
                  onChange={(e) => setNewWallet(e.target.value)}
                  disabled={actorRole !== 'admin'}
                  className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1.5 text-xs text-white font-mono"
                />
              </div>
              <div>
                <label className="block text-[11px] text-slate-300 mb-1">Max Batch Qty</label>
                <input
                  type="number"
                  value={newMaxQty}
                  onChange={(e) => setNewMaxQty(e.target.value)}
                  disabled={actorRole !== 'admin'}
                  className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1.5 text-xs text-white font-mono"
                />
              </div>
            </div>
            <div>
              <label className="block text-[11px] text-slate-300 mb-1">Primary Assigned Router</label>
              <select
                value={newRouterId}
                onChange={(e) => setNewRouterId(e.target.value)}
                disabled={actorRole !== 'admin'}
                className="w-full bg-[#1e2125] border border-[#3a4149] rounded px-2.5 py-1.5 text-xs text-white"
              >
                {routers.map((rt) => (
                  <option key={rt.id} value={rt.id}>
                    {rt.id} ({rt.hotspotName} - ROS {rt.rosVersion})
                  </option>
                ))}
              </select>
            </div>
            <button
              type="submit"
              disabled={actorRole !== 'admin'}
              className="w-full py-1.5 px-3 bg-[#20a8d8] hover:bg-[#1985ac] disabled:opacity-40 text-white text-xs font-semibold rounded transition-colors"
            >
              Create Reseller Account
            </button>
          </form>
        </div>

        {/* Right column: Granular Permissions & Router Assignments for Selected Reseller */}
        {selectedReseller && (
          <div className="lg:col-span-7 bg-[#272b30] border border-[#3a4149] rounded p-4 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#3a4149] pb-3">
              <div>
                <h4 className="text-sm font-semibold text-white">
                  Editing Reseller: {selectedReseller.username}
                </h4>
                <div className="text-xs text-slate-400">{selectedReseller.fullName}</div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={actorRole !== 'admin'}
                  onClick={() => onToggleResellerStatus(selectedReseller.id)}
                  className={`px-3 py-1 text-xs font-semibold rounded ${
                    selectedReseller.status === 'active'
                      ? 'bg-[#f86c6b] text-white'
                      : 'bg-[#4dbd74] text-white'
                  } disabled:opacity-40`}
                >
                  {selectedReseller.status === 'active' ? 'Suspend Account' : 'Activate Account'}
                </button>
              </div>
            </div>

            {/* Assigned Routers */}
            <div>
              <div className="text-xs font-semibold text-white mb-2">
                1. Assigned MikroTik Router Sessions (Strict Isolation)
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                {routers.map((rt) => {
                  const isAssigned = selectedReseller.assignedRouters.includes(rt.id);
                  return (
                    <label
                      key={rt.id}
                      className={`flex items-center gap-2 p-2.5 rounded border text-xs cursor-pointer ${
                        isAssigned
                          ? 'bg-[#1e2125] border-[#20a8d8] text-white'
                          : 'bg-[#1e2125]/50 border-[#3a4149] text-slate-400'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={isAssigned}
                        disabled={actorRole !== 'admin'}
                        onChange={() => handleToggleRouterAssignment(rt.id)}
                      />
                      <div>
                        <div className="font-semibold">{rt.id}</div>
                        <div className="text-[10px] text-slate-400">ROS {rt.rosVersion}</div>
                      </div>
                    </label>
                  );
                })}
              </div>
            </div>

            {/* Allowed Profiles */}
            <div>
              <div className="text-xs font-semibold text-white mb-2">
                2. Permitted Hotspot Voucher Profiles
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                {uniqueProfileNames.map((pName) => {
                  const isAllowed = selectedReseller.allowedProfiles.includes(pName);
                  return (
                    <label
                      key={pName}
                      className={`flex items-center gap-2 p-2 rounded border text-xs cursor-pointer ${
                        isAllowed
                          ? 'bg-[#1e2125] border-[#4dbd74] text-white'
                          : 'bg-[#1e2125]/50 border-[#3a4149] text-slate-400'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={isAllowed}
                        disabled={actorRole !== 'admin'}
                        onChange={() => handleToggleProfileAssignment(pName)}
                      />
                      <span className="truncate">{pName}</span>
                    </label>
                  );
                })}
              </div>
            </div>

            {/* Granular Capabilities */}
            <div>
              <div className="text-xs font-semibold text-white mb-2">
                3. Granular Operation Permissions
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                {PERMISSION_LABELS.map((perm) => {
                  const enabled = selectedReseller.permissions[perm.key];
                  return (
                    <label
                      key={perm.key}
                      className={`flex items-center gap-2 p-2.5 rounded border text-xs cursor-pointer ${
                        enabled
                          ? 'bg-[#1e2125] border-emerald-500/70 text-white'
                          : 'bg-[#1e2125]/40 border-[#3a4149] text-slate-400'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={enabled}
                        disabled={actorRole !== 'admin'}
                        onChange={() => handleTogglePermission(perm.key)}
                      />
                      <span>{perm.label}</span>
                    </label>
                  );
                })}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};
