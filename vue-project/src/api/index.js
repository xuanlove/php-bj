import axios from 'axios'

const api = axios.create({
  baseURL: '/api.php',
  timeout: 30000,
  headers: { 'Content-Type': 'application/json' }
})

let csrfToken = null

export function resetCsrfToken() { csrfToken = null }

export async function getCsrfToken() {
  if (!csrfToken) {
    try {
      const r = await api.get('/', { params: { action: 'get_csrf_token' } })
      if (r.data.success) csrfToken = r.data.token
    } catch (e) { console.error('CSRF获取失败', e) }
  }
  return csrfToken
}

api.interceptors.request.use(async (config) => {
  if (['POST', 'PUT', 'DELETE'].includes(config.method?.toUpperCase())) {
    const t = await getCsrfToken()
    if (t) config.headers['X-CSRF-Token'] = t
  }
  return config
})

api.interceptors.response.use(r => r.data, async e => {
  // 401 未授权:跳转登录页(公开页除外,避免分享链接被踢)
  if (e.response?.status === 401) {
    const hash = window.location.hash || ''
    if (!hash.startsWith('#/login') && !hash.startsWith('#/share')) {
      window.location.hash = '#/login'
    }
  }
  // CSRF 失效:重置 token 以便下次请求重新获取
  if (e.response?.status === 403 && e.response?.data?.code === 'csrf_error') {
    resetCsrfToken()
  }
  if (e.response) {
    // 业务错误:返回响应体供调用方判断 success
    return e.response.data
  }
  // 网络级错误:reject,让调用方的 catch 生效
  return Promise.reject(e)
})

export const authApi = {
  login: d => api.post('/', { action: 'login', ...d }),
  logout: () => api.post('/', { action: 'logout' }),
  currentUser: () => api.get('/', { params: { action: 'current_user' } }),
  register: d => api.post('/', { action: 'register', ...d }),
  getRegistrationMode: () => api.get('/', { params: { action: 'get_registration_mode' } }),
  updateProfile: d => api.post('/', { action: 'update_profile', ...d }),
  changePassword: d => api.post('/', { action: 'change_password', ...d }),
  generateInvite: () => api.post('/', { action: 'generate_invite' }),
}

export const notesApi = {
  list: p => api.get('/', { params: { action: 'notes_list', ...p } }),
  get: id => api.get('/', { params: { action: 'notes_get', note_id: id } }),
  create: d => api.post('/', { action: 'notes_create', ...d }),
  update: (id, d) => api.post('/', { action: 'notes_update', note_id: id, ...d }),
  delete: id => api.post('/', { action: 'notes_delete', note_id: id }),
  search: k => api.get('/', { params: { action: 'notes_search', keyword: k } }),
  noteExport: (id, fmt) => api.get('/', { params: { action: 'notes_export', note_id: id, format: fmt } }),
}

export const foldersApi = {
  list: () => api.get('/', { params: { action: 'folder_list' } }),
  create: (n, pid) => api.post('/', { action: 'folder_create', name: n, parent_id: pid }),
  update: (id, d) => api.post('/', { action: 'folder_update', folder_id: id, ...d }),
  delete: id => api.post('/', { action: 'folder_delete', folder_id: id }),
}

export const tagsApi = {
  list: () => api.get('/', { params: { action: 'tags_list' } }),
  create: d => api.post('/', { action: 'tags_create', ...d }),
  update: d => api.post('/', { action: 'tags_update', ...d }),
  delete: tag => api.post('/', { action: 'tags_delete', tag }),
  getNoteTags: id => api.get('/', { params: { action: 'tags_get_note_tags', note_id: id } }),
  addToNote: (nid, tid) => api.post('/', { action: 'tags_add_to_note', note_id: nid, tag_id: tid }),
  removeFromNote: (nid, tid) => api.post('/', { action: 'tags_remove_from_note', note_id: nid, tag_id: tid }),
  getNotesByTag: (tagId, l, o) => api.get('/', { params: { action: 'notes_by_tag', tag_id: tagId, limit: l, offset: o } }),
  rename: (o, n) => api.post('/', { action: 'tags_rename', old_tag: o, new_tag: n }),
}

export const aiApi = {
  proofread: (id, p) => api.post('/', { action: 'ai_proofread', note_id: id, provider: p }),
  continue: (id, p) => api.post('/', { action: 'ai_continue', note_id: id, provider: p }),
  summarize: (id, p) => api.post('/', { action: 'ai_summarize', note_id: id, provider: p }),
  rewrite: (id, p, s) => api.post('/', { action: 'ai_rewrite', note_id: id, provider: p, style: s }),
  history: id => api.get('/', { params: { action: 'ai_history', note_id: id } }),
  config: () => api.get('/', { params: { action: 'ai_config' } }),
}

export const shareApi = {
  create: (id, d) => api.post('/', { action: 'share_create', note_id: id, ...d }),
  list: id => api.get('/', { params: { action: 'share_list', note_id: id } }),
  update: (id, d) => api.post('/', { action: 'share_update', share_id: id, ...d }),
  delete: id => api.post('/', { action: 'share_delete', share_id: id }),
  get: token => api.get('/', { params: { action: 'share_get', token } }),
  getContent: (token, password) => api.post('/', { action: 'share_content', token, password }),
}

export const noteSharesApi = {
  shareToUser: (nid, u, p) => api.post('/', { action: 'note_share_to_user', note_id: nid, username: u, permission: p }),
  getReceived: () => api.get('/', { params: { action: 'note_share_list_received' } }),
  getSent: () => api.get('/', { params: { action: 'note_share_list_sent' } }),
  getCollaborators: nid => api.get('/', { params: { action: 'note_share_collaborators', note_id: nid } }),
  updatePermission: (sid, p) => api.post('/', { action: 'note_share_update_permission', share_id: sid, permission: p }),
  revoke: sid => api.post('/', { action: 'note_share_revoke', share_id: sid }),
}

export const commentsApi = {
  list: nid => api.get('/', { params: { action: 'comment_list', note_id: nid } }),
  add: (nid, c, pid) => api.post('/', { action: 'comment_add', note_id: nid, content: c, parent_id: pid }),
  update: (cid, c) => api.post('/', { action: 'comment_update', comment_id: cid, content: c }),
  delete: cid => api.post('/', { action: 'comment_delete', comment_id: cid }),
  count: nid => api.get('/', { params: { action: 'comment_count', note_id: nid } }),
}

export const recycleApi = {
  list: (l, o) => api.get('/', { params: { action: 'recycle_bin_list', limit: l, offset: o } }),
  restore: id => api.post('/', { action: 'note_restore', note_id: id }),
  permanentDelete: id => api.post('/', { action: 'note_permanent_delete', note_id: id }),
  empty: () => api.post('/', { action: 'recycle_bin_empty' }),
  stats: () => api.get('/', { params: { action: 'recycle_bin_stats' } }),
}

export const notificationsApi = {
  list: (l, o) => api.get('/', { params: { action: 'notifications_list', limit: l, offset: o } }),
  unreadCount: () => api.get('/', { params: { action: 'notifications_unread_count' } }),
  markRead: id => api.post('/', { action: 'notifications_mark_read', notification_id: id }),
  markAllRead: () => api.post('/', { action: 'notifications_mark_all_read' }),
  delete: id => api.post('/', { action: 'notifications_delete', notification_id: id }),
  clear: () => api.post('/', { action: 'notifications_clear' }),
}

export const versionApi = {
  list: (nid, l, o) => api.get('/', { params: { action: 'version_list', note_id: nid, limit: l, offset: o } }),
  get: vid => api.get('/', { params: { action: 'version_get', version_id: vid } }),
  save: (nid, c, d) => api.post('/', { action: 'version_save', note_id: nid, content: c, change_description: d }),
  rollback: (nid, vid) => api.post('/', { action: 'version_rollback', note_id: nid, version_id: vid }),
  compare: (v1, v2) => api.get('/', { params: { action: 'version_compare', version1_id: v1, version2_id: v2 } }),
  delete: vid => api.post('/', { action: 'version_delete', version_id: vid }),
}

export const settingsApi = {
  get: () => api.get('/', { params: { action: 'settings_get' } }),
  update: d => api.post('/', { action: 'settings_update', ...d }),
  reset: () => api.post('/', { action: 'settings_reset' }),
  uiTemplates: () => api.get('/', { params: { action: 'ui_templates_list' } }),
  currentTemplate: () => api.get('/', { params: { action: 'ui_template_current' } }),
}

export const templatesApi = {
  list: cat => api.get('/', { params: { action: 'templates_list', category: cat } }),
  get: id => api.get('/', { params: { action: 'templates_get', template_id: id } }),
  create: d => api.post('/', { action: 'templates_create', ...d }),
  update: (id, d) => api.post('/', { action: 'templates_update', template_id: id, ...d }),
  delete: id => api.post('/', { action: 'templates_delete', template_id: id }),
  use: id => api.get('/', { params: { action: 'templates_use', template_id: id } }),
  duplicate: id => api.post('/', { action: 'templates_duplicate', template_id: id }),
  categories: () => api.get('/', { params: { action: 'templates_categories' } }),
}

export const teamsApi = {
  list: () => api.get('/', { params: { action: 'teams_list' } }),
  create: d => api.post('/', { action: 'teams_create', ...d }),
  get: id => api.get('/', { params: { action: 'teams_get', team_id: id } }),
  update: (id, d) => api.post('/', { action: 'teams_update', team_id: id, ...d }),
  delete: id => api.post('/', { action: 'teams_delete', team_id: id }),
  invite: (id, d) => api.post('/', { action: 'teams_invite', team_id: id, ...d }),
  addMember: (id, d) => api.post('/', { action: 'teams_add_member', team_id: id, ...d }),
  removeMember: (id, uid) => api.post('/', { action: 'teams_remove_member', team_id: id, user_id: uid }),
  acceptInvite: t => api.post('/', { action: 'teams_accept_invite', token: t }),
  getNotes: (id, fid) => api.get('/', { params: { action: 'teams_get_notes', team_id: id, folder_id: fid } }),
  shareNote: (nid, tid, p) => api.post('/', { action: 'teams_share_note', note_id: nid, team_id: tid, permission: p }),
  getFolders: id => api.get('/', { params: { action: 'teams_get_folders', team_id: id } }),
  createFolder: (tid, d) => api.post('/', { action: 'teams_create_folder', team_id: tid, ...d }),
  updateMemberRole: (tid, uid, r) => api.post('/', { action: 'teams_update_member_role', team_id: tid, user_id: uid, role: r }),
}

export const apiKeysApi = {
  list: () => api.get('/', { params: { action: 'api_keys_list' } }),
  create: (n, p) => api.post('/', { action: 'api_keys_create', name: n, permissions: p }),
  delete: id => api.post('/', { action: 'api_keys_delete', key_id: id }),
  toggle: id => api.post('/', { action: 'api_keys_toggle', key_id: id }),
}

export const twoFactorApi = {
  status: () => api.get('/', { params: { action: '2fa_status' } }),
  generate: () => api.get('/', { params: { action: '2fa_generate' } }),
  enable: (s, c) => api.post('/', { action: '2fa_enable', secret: s, code: c }),
  disable: c => api.post('/', { action: '2fa_disable', code: c }),
  verify: c => api.post('/', { action: '2fa_verify', code: c }),
}

export const loginLogApi = {
  history: (l, o) => api.get('/', { params: { action: 'login_history', limit: l, offset: o } }),
  stats: () => api.get('/', { params: { action: 'login_stats' } }),
  checkLockout: u => api.post('/', { action: 'check_lockout', username: u }),
}

export const userStatsApi = {
  get: () => api.get('/', { params: { action: 'user_stats' } }),
}

export const healthApi = {
  check: () => api.get('/', { params: { action: 'health' } }),
}

export const attachmentsApi = {
  upload: (nid, file) => {
    const fd = new FormData()
    fd.append('note_id', nid)
    fd.append('file', file)
    return api.post('/', fd, {
      params: { action: 'attachment_upload' },
      headers: { 'Content-Type': 'multipart/form-data' }
    })
  },
  list: nid => api.get('/', { params: { action: 'attachment_list', note_id: nid } }),
  delete: id => api.post('/', { action: 'delete_attachment', attachment_id: id }),
}

export const adminApi = {
  stats: () => api.get('/', { params: { action: 'admin_stats' } }),
  allNotes: () => api.get('/', { params: { action: 'admin_all_notes' } }),
  updateNote: (id, d) => api.post('/', { action: 'admin_update_note', note_id: id, ...d }),
  deleteNote: id => api.post('/', { action: 'admin_delete_note', note_id: id }),
  allUsers: () => api.get('/', { params: { action: 'admin_all_users' } }),
  toggleUserStatus: (id, s) => api.post('/', { action: 'admin_toggle_user_status', user_id: id, status: s }),
  deleteUser: id => api.post('/', { action: 'admin_delete_user', user_id: id }),
  getSettings: () => api.get('/', { params: { action: 'admin_get_settings' } }),
  saveSettings: d => api.post('/', { action: 'admin_save_settings', ...d }),
  inviteList: (s, p) => api.get('/', { params: { action: 'invite_list', status: s, page: p } }),
  generateInvite: (c, d) => api.post('/', { action: 'invite_generate', count: c, expires_days: d }),
  deleteInvite: id => api.post('/', { action: 'invite_delete', id }),
  batchDeleteInvite: ids => api.post('/', { action: 'invite_batch_delete', ids }),
  backupConfigs: () => api.get('/', { params: { action: 'backup_configs' } }),
  createBackup: d => api.post('/', { action: 'backup_create', ...d }),
  updateBackup: (id, d) => api.post('/', { action: 'backup_update', id, ...d }),
  deleteBackup: id => api.post('/', { action: 'backup_delete', id }),
  performBackup: id => api.post('/', { action: 'backup_perform', backup_id: id }),
  backupLogs: cid => api.get('/', { params: { action: 'backup_logs', config_id: cid } }),
  testConnection: d => api.post('/', { action: 'backup_test_connection', ...d }),
  storageTypes: () => api.get('/', { params: { action: 'backup_storage_types' } }),
}

export default api