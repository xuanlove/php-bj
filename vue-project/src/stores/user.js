import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi, settingsApi } from '@/api'

export const useUserStore = defineStore('user', () => {
  const user = ref(null)
  const settings = ref(null)

  const isLoggedIn = computed(() => !!user.value)
  const isAdmin = computed(() => user.value?.role === 'admin')

  async function fetchCurrentUser() {
    try {
      const r = await authApi.currentUser()
      if (r.success) user.value = r.user
    } catch (e) { user.value = null }
  }

  async function login(data) {
    const r = await authApi.login(data)
    if (r.success) { user.value = r.user; await fetchSettings() }
    return r
  }

  async function logout() {
    try { await authApi.logout() } finally { user.value = null; settings.value = null }
  }

  async function fetchSettings() {
    try {
      const r = await settingsApi.get()
      if (r.success) settings.value = r.settings
    } catch (e) { /* ignore */ }
  }

  async function updateSettings(data) {
    const r = await settingsApi.update(data)
    if (r.success) await fetchSettings()
    return r
  }

  async function updateProfile(data) {
    const r = await authApi.updateProfile(data)
    if (r.success) await fetchCurrentUser()
    return r
  }

  async function changePassword(oldPassword, newPassword) {
    return await authApi.changePassword({ old_password: oldPassword, new_password: newPassword })
  }

  return { user, settings, isLoggedIn, isAdmin, fetchCurrentUser, login, logout, fetchSettings, updateSettings, updateProfile, changePassword }
})