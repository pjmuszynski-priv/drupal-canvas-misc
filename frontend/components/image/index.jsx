import { Image as ResponsiveImage } from 'drupal-canvas';

const disclosureLabels = {
  ai_generated: 'AI generated',
  ai_generated_autonomous: 'AI generated',
  ai_deepfake: 'AI generated',
  ai_assisted: 'AI assisted',
  ai_assisted_hitl: 'AI assisted',
  ai_partly_assisted: 'AI assisted',
};

const Image = ({ image, aiDisclosure = 'none' }) => {
  if (!image?.src) {
    return null;
  }

  const { src, alt, width, height } = image;
  const disclosureLabel = disclosureLabels[aiDisclosure];

  return (
    <figure className="relative my-8 w-fit max-w-full">
      <ResponsiveImage
        {...{ src, alt, width, height }}
        className="max-w-full"
      />
      {disclosureLabel && (
        <span className="absolute top-3 left-3 bg-black/80 px-2 py-1 text-xs font-medium text-white">
          {disclosureLabel}
        </span>
      )}
    </figure>
  );
};

export default Image;
