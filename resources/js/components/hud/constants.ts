/** Particle budget of the JarvisCore canvas layer. */
export const MAX_PARTICLES_FULL = 120;
export const MAX_PARTICLES_COMPACT = 30;

/** Composer limits enforced by the interaction footer. */
export const MESSAGE_MAX_CHARS = 8000;
export const MESSAGE_COUNTER_THRESHOLD = 7500;
export const MESSAGE_MAX_LINES = 6;

/** How long the header connectors tooltip stays visible after `Alt+2` (UI-04). */
export const SHORTCUT_TOOLTIP_MS = 2500;

/** Radial bars of the JarvisCore speech frames (UI-07). */
export const CORE_RADIAL_BAR_COUNT = 48;
/** Bar length in px at level 0; it grows by `level × amplitude` (UI-07, UI-09). */
export const CORE_BAR_MIN_LENGTH = 3;
export const CORE_BAR_MAX_AMPLITUDE_FULL = 64;
export const CORE_BAR_MAX_AMPLITUDE_COMPACT = 16;
/** Segments of the `MIC` and `VOZ` meters (UI-09). */
export const CORE_METER_SEGMENTS = 12;

/** Scan bar shown before a new visualization section appears (UI-12). */
export const VISUALIZATION_SCAN_MS = 700;
/** Typing effect of the text sections (UI-12): per character, capped in total. */
export const VISUALIZATION_TYPING_MS_PER_CHAR = 12;
export const VISUALIZATION_TYPING_MAX_MS = 1200;

/** Signature transition (UI-06): the core flies between the stage center and the top-left corner in `--dur-fly`. */
export const HUD_STAGE_TRANSITION_MS = 700;
/** Easings of RF-01 for the flight: X axis `--ease-fly`, Y axis `--ease-curve-y` (curved path). */
export const HUD_STAGE_EASE_X = 'cubic-bezier(.2,.8,.2,1)';
export const HUD_STAGE_EASE_Y = 'cubic-bezier(.6,0,.3,1)';
/** Scan sweep that opens the sequence; the core leaves once the beam is under way (UI-06). */
export const HUD_STAGE_SCAN_MS = 350;
export const HUD_STAGE_CORE_DELAY_OPEN_MS = 120;
/** On close the panels leave first and the core flies back after them (reverse order). */
export const HUD_STAGE_CORE_DELAY_CLOSE_MS = 250;

/** One-shot error flash of the JarvisCore (UI-07): ring recomposes in 900 ms; reduced motion keeps a red border for 1 s. */
export const CORE_ERROR_FLASH_MS = 900;
export const CORE_ERROR_FLASH_REDUCED_MS = 1000;
