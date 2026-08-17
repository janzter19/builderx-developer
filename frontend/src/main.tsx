import { Component, StrictMode, type ErrorInfo, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { reloadPagePreservingScroll } from '@/lib/page-refresh'

type AppErrorBoundaryState = { error: Error | null }

class AppErrorBoundary extends Component<{ children: ReactNode }, AppErrorBoundaryState> {
  state: AppErrorBoundaryState = { error: null }

  static getDerivedStateFromError(error: Error): AppErrorBoundaryState {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('BuilderX UI render error', error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <main className="flex min-h-screen items-center justify-center bg-background px-6 text-foreground">
          <section className="w-full max-w-xl rounded-lg border border-destructive/40 p-6">
            <h1 className="text-lg font-semibold">BuilderX could not render this view</h1>
            <p className="mt-2 text-sm text-muted-foreground">The page state was rejected while opening Form Builder. Refresh the page and try again.</p>
            <p className="mt-4 break-words rounded-md bg-muted p-3 font-mono text-xs">{this.state.error.message}</p>
            <button type="button" className="mt-4 rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" onClick={reloadPagePreservingScroll}>Refresh</button>
          </section>
        </main>
      )
    }

    return this.props.children
  }
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppErrorBoundary><App /></AppErrorBoundary>
  </StrictMode>,
)
