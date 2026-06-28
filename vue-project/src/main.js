import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/styles/main.scss'
import '@fortawesome/fontawesome-free/css/all.min.css'
import dayjs from 'dayjs'
import 'dayjs/locale/zh-cn'
import relativeTime from 'dayjs/plugin/relativeTime'

dayjs.extend(relativeTime)
dayjs.locale('zh-cn')

// ========================================
// 启动前检查系统安装状态
// 未安装时跳转到 install.html，避免 SPA 加载后所有接口都报错
// ========================================
async function bootstrap() {
  try {
    const r = await fetch('api.php?action=check_install', { credentials: 'same-origin' })
    const data = await r.json()
    if (data.success && !data.installed) {
      // 未安装，跳转到安装向导
      window.location.href = 'install.html'
      return
    }
  } catch (e) {
    // 检查接口本身不可达，可能是后端未就绪，让 SPA 正常加载由各页面处理错误
    console.warn('安装状态检查失败:', e)
  }

  const app = createApp(App)
  app.use(createPinia())
  app.use(router)
  app.mount('#app')
}

bootstrap()