# Design System Strategy: The High-End CRM

## 1. Overview & Creative North Star
This design system is built upon a Creative North Star we define as **"The Lucid Architect."** 

Standard CRM platforms are often cluttered, grid-locked, and visually exhausting. "The Lucid Architect" rejects this by treating data as an editorial experience. We prioritize clarity, intentional breathing room, and a sense of depth that feels architectural rather than flat. By utilizing a "Deep Blue" and "Professional Purple" palette with a sophisticated layering system, we transform a functional tool into a premium workspace. We break the "template" look through tonal shifts rather than harsh lines, creating a UI that feels fluid, high-end, and authoritative.

---

## 2. Colors & Surface Logic

The color system is rooted in Material Design 3 logic but refined for a custom SaaS aesthetic.

### Core Palette
- **Primary (`#003d9b`):** The "Authority Blue." Used for key actions and brand presence.
- **Secondary (`#6b46c1`):** The "Intelligence Purple." Used for accents, secondary actions, and insight-driven components.
- **Background (`#f6fafe`):** A tinted, cool-white that reduces eye strain compared to pure `#ffffff`.

### Surface Hierarchy (The Layering Principle)
We move away from standard grids by using **Tonal Layering**. Instead of using borders to separate content, we use the `surface-container` tiers to define "elevation."
- **Base Layer:** `surface` (`#f6fafe`)
- **Card/Section Layer:** `surface_container_lowest` (`#ffffff`) for maximum pop.
- **Nested Inner Areas:** `surface_container` (`#eaeef2`) to create a "recessed" feel for secondary data.

**The "No-Line" Rule:** 1px solid borders for sectioning are strictly prohibited. Boundaries must be defined solely through background color shifts or the "Ghost Border" fallback (outline-variant at 15% opacity).

**The "Glass & Gradient" Rule:** For floating modals or navigation hover states, use `surface_container_lowest` with a 80% opacity and a `backdrop-blur: 12px`. For primary CTAs, apply a subtle linear gradient from `primary` to `primary_container` to add "soul" and depth.

---

## 3. Typography: Editorial Authority

We use **Inter** as our primary typeface, chosen for its exceptional legibility in data-heavy environments.

- **Display & Headlines:** Use `display-sm` (2.25rem) for main dashboard headings. The scale between a `headline-lg` and `body-md` is intentionally dramatic to create a clear visual hierarchy that feels like a premium magazine layout.
- **Title Tones:** Use `title-lg` (1.375rem) for card headers. These should always be in `on_surface` to maintain high contrast.
- **Labeling:** `label-md` (0.75rem) should be used for metadata and small captions, often paired with `on_surface_variant` to recede visually.

The typography is the "skeleton" of the system; by giving headlines significant weight and body text ample line-height (1.5x), the CRM remains readable even during intensive data entry.

---

## 4. Elevation & Depth

We achieve a high-end feel by mimicking natural light.

- **Ambient Shadows:** Standard cards use a custom shadow: `0 4px 20px -2px rgba(23, 28, 31, 0.06)`. This uses the `on_surface` color for the shadow tint, making the lift feel organic.
- **Ghost Borders:** When accessibility requires a container edge (e.g., in a search input), use `outline_variant` (`#c3c6d6`) at **10% opacity**. It should be barely felt, not seen.
- **The Depth Stack:** 
    1. Navigation Sidebar: `surface_container_low`
    2. Main Content Area: `surface`
    3. Content Cards: `surface_container_lowest` (White) with Ambient Shadow.

---

## 5. Components

### Navigation: The Sidebar
The sidebar should feel like a sturdy anchor. Use `on_surface_variant` for inactive icons and text. The active state should use a `secondary_container` background with a `9999px` (Full) rounded right-edge to create a "pill" highlight that feels soft and modern.

### Buttons
- **Primary:** Gradient fill (`primary` to `primary_container`), `8px` rounded corners, white text.
- **Secondary:** `secondary_fixed` background with `on_secondary_fixed` text. No border.
- **Tertiary/Ghost:** No background. Text color is `primary`. On hover, apply a `surface_container_high` background.

### Modern Form Fields
Fields should not look like boxes.
- **State:** Use `surface_container_low` as the background.
- **Focus:** Transition the background to `surface_container_lowest` and add a 2px "Ghost Border" using the `primary` color at 40% opacity.
- **Corners:** `8px` (DEFAULT roundedness).

### Status Badges
Forbid high-saturation "traffic light" colors.
- Use the `_container` tokens (e.g., `tertiary_container` for "Success" or `error_container` for "Overdue").
- Pair with the corresponding `on_` color for text to ensure a "soft" but readable look.

### Cards & Lists
**Forbid divider lines.** Separate list items using `8px` of vertical whitespace. If separation is critical, use a `1px` tall stripe of `surface_container_highest` that does not touch the edges of the container (inset divider).

---

## 6. Do's and Don'ts

### Do
- **Do** use `secondary` (Purple) for "Insight" elements, like tooltips, charts, or growth indicators.
- **Do** maximize whitespace. If a card feels "crowded," increase the padding from `1rem` to `1.5rem`.
- **Do** use `surface_tint` sparingly to highlight the most important interactive element on the page.

### Don't
- **Don't** use pure black (`#000000`) for text. Use `on_surface` (`#171c1f`) to maintain a premium, soft-touch feel.
- **Don't** use 100% opaque borders. They create "visual noise" that makes the CRM feel like legacy software.
- **Don't** use default Inter tracking. For `display` styles, slightly tighten the letter spacing (`-0.02em`) for a more "designed" editorial appearance.