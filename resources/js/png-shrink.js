/*
 * Gets a picture under a size cap in the browser, keeping its transparency:
 * `await window.fqShrinkPng(file, capKb)`.
 *
 * For cosmetic art straight out of an image generator. An all-ages pet sheet
 * comes back at 1024–2048 pixels and often well over 2 MB, and PHP drops a
 * file past upload_max_filesize before the app ever sees it — Livewire then
 * gets an error page where it expected an answer, and the grown-up gets a
 * console error and no reason. So it is made to fit here first:
 *
 * 1. As a PNG, drawn smaller — but no smaller than 1024px, where a pet's
 *    cells start to go soft.
 * 2. Failing that, as a high-quality WebP at full size: still transparent,
 *    typically a fifth of the size, and turned back into a PNG the moment it
 *    reaches the server (CosmeticArt::asPng()), so nothing after that ever
 *    sees anything but a PNG.
 * 3. Only then smaller still.
 *
 * A file already under the cap, or one that is not a PNG, is handed back
 * untouched. So is one that cannot be got under the cap at all — the caller
 * checks the size and says so rather than sending it.
 */

const PNG_SIDES = [1536, 1280, 1024];
const LAST_RESORT_SIDES = [900, 768];

async function encode(image, width, height, type, quality) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(image, 0, 0, width, height);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, quality));

    // A browser that cannot write the type hands back a PNG instead.
    return blob && blob.type === type ? blob : null;
}

window.fqShrinkPng = async function (file, capKb) {
    const cap = capKb * 1024;

    if (! file || file.type !== 'image/png' || file.size <= cap || ! window.createImageBitmap) {
        return file;
    }

    let image;

    try {
        image = await createImageBitmap(file);
    } catch (error) {
        return file;
    }

    const longest = Math.max(image.width, image.height);
    const sized = (side) => [Math.round(image.width * side / longest), Math.round(image.height * side / longest)];
    const named = (extension) => file.name.replace(/\.[^.]+$/, '') + '.' + extension;

    for (const side of PNG_SIDES) {
        if (side >= longest) {
            continue;
        }

        const blob = await encode(image, ...sized(side), 'image/png');

        if (blob && blob.size <= cap) {
            return new File([blob], file.name, { type: 'image/png' });
        }
    }

    for (const quality of [0.95, 0.9, 0.85]) {
        const blob = await encode(image, ...sized(Math.min(longest, 1536)), 'image/webp', quality);

        if (blob && blob.size <= cap) {
            return new File([blob], named('webp'), { type: 'image/webp' });
        }
    }

    for (const side of LAST_RESORT_SIDES) {
        if (side >= longest) {
            continue;
        }

        const blob = await encode(image, ...sized(side), 'image/png');

        if (blob && blob.size <= cap) {
            return new File([blob], file.name, { type: 'image/png' });
        }
    }

    return file;
};
