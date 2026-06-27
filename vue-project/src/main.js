import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/styles/main.scss'
import dayjs from 'dayjs'
import 'dayjs/locale/zh-cn'
import relativeTime from 'dayjs/plugin/relativeTime'
import { installApi } from './api'

dayjs.extend(relativeTime)
dayjs.locale('zh-cn')

// 启动前先检测系统是否已安装，未安装则跳转到安装向导
async function bootstrap() {
  try {
    const data = await installApi.checkStatus()
    if (data && data.installed === false) {
      // 系统未安装，跳转到安装向导
      window.location.replace('install.html')
      return
    }
  } catch (e) {
    // 检测失败时继续加载应用，由后续接口报错处理
    console.warn('安装状态检测失败：', e)
  }

  const app = createApp(App)
  app.use(createPinia())
  app.use(router)
  app.mount('#app')
}

bootstrap()
