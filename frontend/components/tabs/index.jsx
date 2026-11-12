'use client';

import { useEffect, useId, useRef, useState } from 'react';

export default function Tabs({ header, subheader, items }) {
  const id = useId();
  const slotRef = useRef(null);
  const tabListRef = useRef(null);
  const panelsRef = useRef(new Map());
  const [tabs, setTabs] = useState([]);
  const [selection, setSelection] = useState(null);
  const activeId = tabs.some((tab) => tab.id === selection) ? selection : tabs[0]?.id;

  useEffect(() => {
    const slot = slotRef.current;
    const panelIds = new WeakMap();
    let nextId = 0;

    function readSlot() {
      const panels = Array.from(slot.querySelectorAll('[data-tab-item]')).filter(
        (panel) => panel.parentElement.closest('[data-tabs-items], [data-tab-item]') === slot,
      );

      const panelsById = new Map();
      const nextTabs = panels.map((panel) => {
        if (!panelIds.has(panel)) {
          panelIds.set(panel, `${id}-panel-${nextId++}`);
        }
        const panelId = panelIds.get(panel);
        panelsById.set(panelId, panel);
        return { id: panelId, title: panel.dataset.tabItem };
      });

      panelsRef.current = panelsById;
      setTabs(nextTabs);
    }

    readSlot();
    const observer = new MutationObserver(readSlot);
    observer.observe(slot, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['data-tab-item'],
    });

    return () => observer.disconnect();
  }, [id]);

  useEffect(() => {
    const panels = panelsRef.current;
    for (const [panelId, panel] of panels) {
      panel.setAttribute('id', panelId);
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', `${panelId}-tab`);
      panel.setAttribute('tabindex', '0');
      panel.toggleAttribute('hidden', panelId !== activeId);
    }

    return () => {
      for (const panel of panels.values()) {
        panel.removeAttribute('hidden');
        panel.removeAttribute('id');
        panel.removeAttribute('role');
        panel.removeAttribute('aria-labelledby');
        panel.removeAttribute('tabindex');
      }
    };
  }, [activeId, tabs]);

  function handleKeyDown(event, index) {
    let nextIndex;
    switch (event.key) {
      case 'ArrowLeft':
        nextIndex = (index + tabs.length - 1) % tabs.length;
        break;
      case 'ArrowRight':
        nextIndex = (index + 1) % tabs.length;
        break;
      case 'Home':
        nextIndex = 0;
        break;
      case 'End':
        nextIndex = tabs.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    setSelection(tabs[nextIndex].id);
    tabListRef.current.children[nextIndex]?.focus();
  }

  return (
    <div>
      {header && <h2>{header}</h2>}
      {subheader && <p>{subheader}</p>}
      {tabs.length > 0 && (
        <div
          ref={tabListRef}
          role="tablist"
          aria-label={header || 'Tabs'}
          style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginBlock: '1rem' }}
        >
          {tabs.map((tab, index) => (
            <button
              key={tab.id}
              id={`${tab.id}-tab`}
              type="button"
              role="tab"
              aria-selected={tab.id === activeId}
              aria-controls={tab.id}
              tabIndex={tab.id === activeId ? 0 : -1}
              onClick={() => setSelection(tab.id)}
              onKeyDown={(event) => handleKeyDown(event, index)}
              style={{
                background: tab.id === activeId ? '#0f172a' : '#fff',
                color: tab.id === activeId ? '#fff' : '#0f172a',
                border: '1px solid #64748b',
                borderRadius: '999px',
                cursor: 'pointer',
                fontWeight: 500,
                padding: '0.375rem 0.875rem',
              }}
            >
              {tab.title}
            </button>
          ))}
        </div>
      )}
      <div ref={slotRef} data-tabs-items>
        {items}
      </div>
    </div>
  );
}
