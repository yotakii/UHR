import { createRoot } from 'react-dom/client'
import './index.css'
import { applyDocument } from './lib/store'
import { installRouter } from './lib/router'
import App from './App'

applyDocument()
installRouter()
createRoot(document.getElementById('root')!).render(<App />)
