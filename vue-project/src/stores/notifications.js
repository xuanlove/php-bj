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
      if (n && !n.is_read) {
        n.is_read = true
        unreadCount.value = Math.max(0, unreadCount.value - 1)
      }
    }
  }

  async function markAllAsRead() {
    const r = await notificationsApi.markAllRead()
    if (r.success) {
      notifications.value.forEach(n => n.is_read = true)
      unreadCount.value = 0
    }
  }

  async function deleteNotification(id) {
    const n = notifications.value.find(x => x.id === id)
    if (n && !n.is_read) {
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }
    await notificationsApi.delete(id)
    notifications.value = notifications.value.filter(x => x.id !== id)
  }

  async function clearAll() {
    const r = await notificationsApi.clear()
    if (r.success) {
      notifications.value = []
      unreadCount.value = 0
    }
  }

  return { notifications, loading, unreadCount, fetchNotifications, fetchUnreadCount, markAsRead, markAllAsRead, deleteNotification, clearAll }
})