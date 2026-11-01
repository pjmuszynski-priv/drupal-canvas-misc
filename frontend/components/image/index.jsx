import { Image as ResponsiveImage } from 'drupal-canvas';

const disclosureIcons = {
  ai_generated: {
    src: '/ai-disclosure/ai_generated--black.svg',
    alt: 'AI generated',
    width: 1790,
  },
  ai_assisted: {
    src: '/ai-disclosure/ai_modified--black.svg',
    alt: 'AI assisted',
    width: 1701,
  },
};

const Image = ({ image }) => {
  if (!image?.src) {
    return null;
  }

  const aiDisclosure = image.aiDisclosure ?? 'none';
  console.log(aiDisclosure);

  const { src, alt, width, height } = image;
  const disclosureIcon = disclosureIcons[aiDisclosure];

  return (
    <figure className="my-8 w-fit max-w-full">
      <div>test</div>
      <div className="relative">
        <ResponsiveImage
          {...{ src, alt, width, height }}
          className="block max-w-full"
        />
        {disclosureIcon && (
          <img
            src={disclosureIcon.src}
            alt={disclosureIcon.alt}
            width={disclosureIcon.width}
            height={567}
            className="pointer-events-none absolute right-[3%] bottom-[3%] h-auto w-[22%] min-w-16 max-w-full"
          />
        )}
      </div>
    </figure>
  );
};

export default Image;
