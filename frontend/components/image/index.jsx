import { Image as ResponsiveImage } from 'drupal-canvas';

const Image = ({ image }) => {
  if (!image?.src) {
    return null;
  }

  const aiDisclosure = image.aiDisclosure ?? 'none';
  console.log(aiDisclosure);

  const { src, alt, width, height } = image;

  return (
    <figure className="my-8 w-fit max-w-full">
      <div>test</div>
      <ResponsiveImage
        {...{ src, alt, width, height }}
        className="max-w-full"
      />

    </figure>
  );
};

export default Image;
