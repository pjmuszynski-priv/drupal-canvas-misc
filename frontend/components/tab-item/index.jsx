'use client';

export default function TabItem({ tabLabel, body }) {
  return (
    <div data-tab-item={tabLabel?.trim() || 'Tab'}>
      {body}
    </div>
  );
}
