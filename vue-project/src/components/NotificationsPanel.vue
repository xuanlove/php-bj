<template>
  <div class="modal-overlay" role="dialog" aria-modal="true" @click.self="$emit('close')">
    <div class="modal notifications-modal">
      <div class="modal-header">
        <h3>通知</h3>
        <div class="header-actions">
          <button class="btn-ghost-sm" @click="markAllAsRead" v-if="unreadCount > 0">全部已读</button>
          <button class="icon-btn-sm" @click="$emit('close')"><i class="fas fa-times"></i></button>
        </div>
      </div>
      <div class="modal-body">
        <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
        <div v-else-if="notifications.length === 0" class="empty"><i class="fas fa-bell-slash"></i><p>暂无通知</p></div>
        <div v-else class="notification-list">
          <div v-for="n in notifications" :key="n.id" :class="['notification-item', { unread: !n.is_read }]">
            <div class="notification-icon"><i :class="getIcon(n.type)"></i></div>
            <div class="notification-content">
              <div class="notification-title">{{ n.title }}</div>
              <div class="notification-text">{{ n.content }}</div>
              <div class="notification-time">{{ formatTime(n.created_at) }}</div>
            </div>
            <button class="icon-btn-sm" @click.stop="deleteNotification(n.id)"><i class="fas fa-trash"></i></button>
          </div>
        </div>
      </div>
      <div class="modal-footer" v-if="notifications.length > 0">
        <button class="btn-ghost-sm" @click="clearAll">清空全部</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useNotificationsStore } from '@/stores/notifications'
import dayjs from 'dayjs'

const emit = defineEmits(['close'])
const store = useNotificationsStore()
const { notifications, loading, unreadCount } = storeToRefs(store)

onMounted(() => store.fetchNotifications())

function getIcon(type) {
  const m = { system: 'fas fa-cog', share: 'fas fa-share-alt', comment: 'fas fa-comment', ai_complete: 'fas fa-robot', security: 'fas fa-shield-alt' }
  return m[type] || 'fas fa-bell'
}

function formatTime(t) { return dayjs(t).fromNow() }
function markAllAsRead() { store.markAllAsRead() }
function deleteNotification(id) { store.deleteNotification(id) }
function clearAll() { if (confirm('确定清空所有通知？')) store.clearAll() }
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}
.modal {
  background: var(--secondary-bg, #1e1e2e);
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  max-height: 80vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.notifications-modal { width: 480px; max-width: 90vw; max-height: 80vh; display: flex; flex-direction: column; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border-color); }
.modal-header h3 { font-size: 18px; }
.header-actions { display: flex; align-items: center; gap: 8px; }
.modal-body { flex: 1; overflow-y: auto; }
.loading, .empty { text-align: center; padding: 40px; color: var(--text-muted); }
.loading i { font-size: 24px; }
.empty i { font-size: 48px; margin-bottom: 16px; }
.notification-list { padding: 8px; }
.notification-item { display: flex; gap: 12px; padding: 12px; border-radius: 8px; cursor: pointer; transition: background 0.2s; }
.notification-item:hover { background: var(--hover-bg); }
.notification-item.unread { background: rgba(212, 175, 55, 0.1); }
.notification-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: var(--hover-bg); border-radius: 10px; flex-shrink: 0; }
.notification-icon i { color: var(--accent-gold); }
.notification-content { flex: 1; min-width: 0; }
.notification-title { font-size: 14px; margin-bottom: 4px; }
.notification-text { font-size: 13px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.notification-time { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.modal-footer { padding: 12px 20px; border-top: 1px solid var(--border-color); text-align: center; }
.icon-btn-sm { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: transparent; border: none; border-radius: 6px; color: var(--text-secondary); cursor: pointer; }
.icon-btn-sm:hover { background: var(--hover-bg); color: var(--danger); }
.btn-ghost-sm { padding: 6px 12px; background: transparent; border: none; color: var(--text-secondary); cursor: pointer; font-size: 13px; }
.btn-ghost-sm:hover { color: var(--text-primary); }
</style>