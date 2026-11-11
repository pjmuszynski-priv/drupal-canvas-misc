import Link from 'next/link';

const TemplateShell = ({ title, main, supplementary }) => (
  <div className="min-h-screen bg-stone-50 text-slate-900">
    <header className="border-b border-stone-200 bg-white px-6 py-5">
      <div className="mx-auto flex max-w-6xl items-center justify-between">
        <Link href="/slot-demo/alpha" className="text-lg font-bold tracking-tight">
          Studio / Journal
        </Link>
        <nav aria-label="Demo pages" className="flex gap-5 text-sm">
          <Link href="/slot-demo/alpha" className="hover:underline">Alpha</Link>
          <Link href="/slot-demo/beta" className="hover:underline">Beta</Link>
        </nav>
      </div>
    </header>
    <div className="bg-slate-900 px-6 py-20 text-white">
      <div className="mx-auto max-w-6xl">
        <p className="mb-5 text-xs font-semibold uppercase tracking-widest text-amber-300">
          The editorial collection
        </p>
        <h1 className="max-w-3xl text-4xl font-semibold leading-tight tracking-tight md:text-6xl">
          {title}
        </h1>
        <p className="mt-6 max-w-xl text-lg leading-relaxed text-slate-300">
          Ideas, observations and stories from our team.
        </p>
      </div>
    </div>
    <div className="mx-auto grid max-w-6xl gap-12 px-6 py-14 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <section aria-label="Main content" className="min-w-0 space-y-7">
        {main}
      </section>
      <aside aria-label="Additional content" className="min-w-0 space-y-7 rounded-2xl border border-stone-200 bg-white p-7">
        {supplementary}
      </aside>
    </div>
    <footer className="border-t border-stone-200 px-6 py-8 text-sm text-slate-500">
      <div className="mx-auto max-w-6xl">Studio Journal · A place for considered ideas.</div>
    </footer>
  </div>
);

export default TemplateShell;
