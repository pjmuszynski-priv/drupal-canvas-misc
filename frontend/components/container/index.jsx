const columnClasses = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  3: 'grid-cols-1 md:grid-cols-3',
  4: 'grid-cols-1 md:grid-cols-4',
  6: 'grid-cols-1 md:grid-cols-6',
  12: 'grid-cols-1 md:grid-cols-12',
};

const Container = ({ columns = 1, content }) => {
  const columnCount = Number(columns);

  return (
    <div className={`grid gap-4 ${columnClasses[columnCount] ?? columnClasses[1]}`}>
      {content}
    </div>
  );
};

export default Container;
