import { useEffect } from 'react'

export const APP_NAME = 'WorkSuite Cloud'

/** Sets the browser tab title to "<page> · WorkSuite Cloud" while the page is shown. */
export function useDocumentTitle(title: unknown) {
  useEffect(() => {
    if (typeof title !== 'string' || !title.trim()) return
    const prev = document.title
    document.title = title.includes(APP_NAME) ? title : `${title} · ${APP_NAME}`
    return () => { document.title = prev }
  }, [title])
}
