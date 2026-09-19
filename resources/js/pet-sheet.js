/*
 * How a pet's sprite sheet is laid out: which pose is in which cell.
 *
 * Shared by the pet layer (pets.js) and the still pet on a tile
 * (cosmetic-elements.js), so the two can't crop different cells out of the
 * same picture.
 *
 * There are two layouts. A pet cut from the nine-by-six all-ages sheet has
 * eighteen poses, six by three, and empty paws in play, toss and back, where
 * the app draws the toy. A pet made before that has the old twelve, four by
 * three, with the sheet's own toy drawn into play and toss. Kids own those, so
 * they keep working until a grown-up gives them new art. The server says which
 * one a pet is with its `rig` (App\Models\Cosmetic::rig()); no rig means the
 * old one.
 */

/** Mirrors App\Enums\CosmeticSlot::PET_POSES. */
export const PET_POSES = [
    'idle', 'blink', 'sit', 'crouch', 'jump', 'landed',
    'walk', 'walk2', 'happy', 'surprised', 'held', 'sniff',
    'swipe', 'play', 'toss', 'back', 'sleep', 'toy',
];

/** Mirrors App\Enums\CosmeticSlot::LEGACY_PET_POSES. */
export const LEGACY_PET_POSES = [
    'idle', 'blink', 'crouch', 'jump',
    'walk', 'happy', 'held', 'landed',
    'play', 'toss', 'sleep', 'toy',
];

/**
 * What an old twelve-pose sheet shows for a pose it doesn't have. Its play
 * and toss have a toy drawn in, so back and swipe fall back to poses with
 * empty paws.
 */
const STAND_INS = {
    sit: 'idle',
    walk2: 'walk',
    surprised: 'jump',
    sniff: 'crouch',
    swipe: 'crouch',
    back: 'happy',
};

/**
 * The layout a sheet is in, from its rig — or from a pose count, as the
 * still element is given one.
 *
 * @param {{poses?: number}|number|null|undefined} rig
 * @returns {{poses: string[], cols: number, rows: number, legacy: boolean}}
 */
export function sheetLayout(rig) {
    const count = typeof rig === 'number' ? rig : (rig?.poses ?? 0);

    return count === PET_POSES.length
        ? { poses: PET_POSES, cols: 6, rows: 3, legacy: false }
        : { poses: LEGACY_PET_POSES, cols: 4, rows: 3, legacy: true };
}

/** The CSS background-size that makes one cell fill the box. */
export function sheetSize(layout) {
    return (layout.cols * 100) + '% ' + (layout.rows * 100) + '%';
}

/**
 * Where one pose sits in the sheet, as a background-position pair: a pose
 * the sheet doesn't have is drawn as its stand-in, and an unknown one as idle.
 */
export function posePosition(pose, layout) {
    const found = layout.poses.indexOf(pose);
    const index = Math.max(0, found >= 0 ? found : layout.poses.indexOf(STAND_INS[pose] ?? 'idle'));
    const column = index % layout.cols;
    const row = Math.floor(index / layout.cols);

    // Fractions of the leftover space, which is what a percentage
    // background-position means: column 1 of 4 is 33.3%.
    return (column * 100 / (layout.cols - 1)) + '% ' + (row * 100 / (layout.rows - 1)) + '%';
}
