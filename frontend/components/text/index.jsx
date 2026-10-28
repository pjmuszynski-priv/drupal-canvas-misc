const Text = ({ text = '' }) => {
  return (
    <div
      className="prose max-w-none"
      dangerouslySetInnerHTML={{ __html: text }}
    />
  );
};

export default Text;
