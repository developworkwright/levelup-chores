/*
 * Shrinks a PNG in the browser until it fits under a size cap, keeping its
 * transparency and its shape: `await window.fqShrinkPng(file, capKb)`.
 *
 * For cosmetic art straight out of an image generator. An all-ages pet sheet
 * comes back at 1536 or 2048 pixels and well over 2 MB, and PHP drops a file
 * past upload_max_filesize before the app ever sees it — so the grown-up would
 * get a failed upload and no reason. Drawn smaller here instead, it arrives,
 * and the server cuts it to the size it keeps anyway.
 *
 * A file already under the cap, or one that is not a PNG, is handed back
 * untouched; one that cannot be got under the cap is too, and the server says
 * why in its own words.
 */

const SIDES = [1536, 1280, 1024, 900];

async function toPng(image, width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(image, 0, 0, width, height);

    return await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

window.fqShrinkPng = async function (file, capKb) {
    if (! file || file.type !== 'image/png' || file.size <= capKb * 1024 || ! window.createImageBitmap) {
        return file;
    }

    let image;

    try {
        image = await createImageBitmap(file);
    } catch (error) {
        return file;
    }

    const longest = Math.max(image.width, image.height);

    for (const side of SIDES) {
        if (side >= longest) {
            continue;
        }

        const scale = side / longest;
        const blob = await toPng(image, Math.round(image.width * scale), Math.round(image.height * scale));

        if (blob && blob.size <= capKb * 1024) {
            return new File([blob], file.name, { type: 'image/png' });
        }
    }

    return file;
};
