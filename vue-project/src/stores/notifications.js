import { defineStore } from 'pinia'
import { ref } from 'vue'
import { notificationsApi } from '@/api'

export const useNotificationsStore = defineStore('notifications', () => {
  const notifications = ref([])
  const loading = ref(false)

  const unreadCount = ref(0)

  async function fetchNotifications(limit = 20, offset = 0) {
    loading.value = true
    const r = await notificationsApi.list(limit, offset)
    if (r.success) notifications.value = r.notifications || []
    loading.value = false
  }

  async function fetchUnreadCount() {
    const r = await notificationsApi.unreadCount()
    if (r.success && r.count !== undefined) {
      unreadCount.value = r.count
    }
  }

  async function markAsRead(id) {
    const r = await notificationsApi.markRead(id)
    if (r.success) {
      const n = notifications.value.find(x => x.id === id)
      if (n) n.is_read = true
    }
  }

  async function markAllAsRead() {
    const r = await notificationsApi.markAllRead()
    if (r.success) notifications.value.forEach(n => n.is_read = true)
    return r
  }

  async function deleteNotification(id) {
    const r = await notificationsApi.delete(id)
    if (r.success) notifications.value = notifications.value.filter(x => x.id !== id)
    return r
  }

  async function clearAll() {
    const r = await notificationsApi.clear()
    if (r.success) notifications.value = []
    return r
  }

  return { notifications, loading, unreadCount, fetchNotifications, fetchUnreadCount, markAsRead, markAllAsRead, deleteNotification, clearAll }
})