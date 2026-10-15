import { createRoot } from 'react-dom/client'
import './index.css'
import { applyDocument } from './lib/store'
import App from './App'

applyDocument()
createRoot(document.getElementById('root')!).render(<App />)
