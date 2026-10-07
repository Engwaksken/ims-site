<?php
/**
 * includes/pricing.php
 *
 * Boardroom member-benefit pricing.
 *
 * Rules:
 *  - Only applies to Boardroom bookings. Every other space type is charged
 *    at the space's own listed hourly/half_day/full_day rate, same as always.
 *  - "Private Office" members: 2 hours free per day on Boardroom bookings.
 *    Anything beyond that is billed at a flat UGX 20,000/hour.
 *  - "Dedicated Desk" members: 1 hour free per day on Boardroom bookings,
 *    same UGX 20,000/hour beyond that.
 *  - "Hot Desk" members (or any other/unset member_type - e.g. Boardroom,
 *    Meeting Room, Event Space, which are membership-adjacent values that
 *    don't carry this benefit): no offer - full standard Boardroom rate,
 *    same as a non-Boardroom space would get.
 *  - "Per day" means the free allowance is shared across every active
 *    Boardroom booking that member has *that calendar date*, not reset
 *    per booking - so a second Boardroom booking on the same day only
 *    gets whatever free allowance is left over.
 *
 * Eligibility is based on members.member_type (the membership tier -
 * "Private Office" / "Hot Desk" / "Dedicated Desk" / etc.), NOT
 * members.subscription_plan (that column is actually the billing period -
 * Daily/Weekly/Monthly/Quarterly/Annual - see my-subscription.php).
 *
 * This is intentionally the single source of truth for this calculation -
 * both includes/process-booking.php (authoritative, on save) and
 * get-quote.php (live preview while filling the form) call the same
 * function, so the number shown while booking always matches what
 * actually gets charged.
 */

const BOARDROOM_MEMBER_OVERAGE_RATE = 20000.0;

/**
 * @param mysqli $conn
 * @param int    $member_id
 * @param array  $space               Full row from space_availability (needs
 *                                     space_type, hourly_rate, half_day_rate,
 *                                     full_day_rate).
 * @param string $booking_type        'hourly' | 'half_day' | 'full_day'
 * @param float  $duration_hours      Already-computed duration for this booking.
 * @param string $booking_date        'YYYY-MM-DD' - which day's free allowance to check.
 * @param int|null $exclude_booking_id When editing a booking, exclude its own
 *                                     current row from the "already used today" sum.
 *
 * @return array{
 *   amount: float,
 *   free_hours_applied: float,
 *   billable_hours: float,
 *   rate_applied: float|null,
 *   special_offer: bool,
 *   member_type: string|null,
 * }
 */
function calculate_booking_amount($conn, $member_id, array $space, string $booking_type, float $duration_hours, string $booking_date, ?int $exclude_booking_id = null): array {
    $result = [
        'amount'              => 0.0,
        'free_hours_applied'  => 0.0,
        'billable_hours'      => $duration_hours,
        'rate_applied'        => null,
        'special_offer'       => false,
        'member_type'         => null,
    ];

    // Standard pricing - the space's own listed rates. This is what every
    // non-Boardroom booking uses, and also the fallback for Boardroom
    // bookings by members whose membership tier carries no free-hours offer.
    if ($booking_type === 'half_day') {
        $standard_amount = (float) $space['half_day_rate'];
    } elseif ($booking_type === 'full_day') {
        $standard_amount = (float) $space['full_day_rate'];
    } else {
        $standard_amount = $duration_hours * (float) $space['hourly_rate'];
    }

    $is_boardroom = stripos((string) ($space['space_type'] ?? ''), 'Boardroom') !== false;

    if (!$is_boardroom) {
        $result['amount'] = round($standard_amount, 2);
        return $result;
    }

    // Look up the member's membership tier (member_type) - NOT subscription_plan.
    $member_type = null;
    $mt_result = $conn->query("SELECT member_type FROM members WHERE member_id = " . intval($member_id));
    if ($mt_result && $mt_result->num_rows > 0) {
        $member_type = $mt_result->fetch_assoc()['member_type'];
    }
    $result['member_type'] = $member_type;

    $member_type_lower = strtolower((string) $member_type);

    if (strpos($member_type_lower, 'private') !== false) {
        $free_allowance = 2.0;
    } elseif (strpos($member_type_lower, 'dedicated') !== false) {
        $free_allowance = 1.0;
    } else {
        // Hot Desk / Boardroom / Meeting Room / Event Space / unset - no
        // offer, standard rate.
        $result['amount'] = round($standard_amount, 2);
        return $result;
    }

    // How many Boardroom hours has this member already used today, across
    // any other active booking on this same date (the free allowance is
    // per day, not per booking).
    $date_esc = $conn->real_escape_string($booking_date);
    $exclude_clause = $exclude_booking_id ? " AND booking_id != " . intval($exclude_booking_id) : "";
    $used_query = "SELECT COALESCE(SUM(duration_hours), 0) AS used_hours
                    FROM space_bookings
                    WHERE member_id = " . intval($member_id) . "
                    AND booking_date = '$date_esc'
                    AND space_type LIKE '%Boardroom%'
                    AND booking_status NOT IN ('Cancelled', 'No Show')
                    $exclude_clause";
    $used_result = $conn->query($used_query);
    $hours_used_today = $used_result ? (float) $used_result->fetch_assoc()['used_hours'] : 0.0;

    $remaining_free = max(0.0, $free_allowance - $hours_used_today);
    $free_hours_applied = min($duration_hours, $remaining_free);
    $billable_hours = $duration_hours - $free_hours_applied;

    $result['amount'] = round($billable_hours * BOARDROOM_MEMBER_OVERAGE_RATE, 2);
    $result['free_hours_applied'] = $free_hours_applied;
    $result['billable_hours'] = $billable_hours;
    $result['rate_applied'] = BOARDROOM_MEMBER_OVERAGE_RATE;
    $result['special_offer'] = true;

    return $result;
}