-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 07, 2026 at 09:11 AM
-- Server version: 11.4.13-MariaDB-cll-lve-log
-- PHP Version: 8.4.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `foodbbfd_jerrydino`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'Other',
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `price_unit` varchar(50) DEFAULT 'per person',
  `price_note` varchar(255) DEFAULT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `duration_unit` varchar(20) DEFAULT NULL,
  `length_value` varchar(50) DEFAULT NULL,
  `length` varchar(50) DEFAULT NULL,
  `length_unit` varchar(20) DEFAULT NULL,
  `icon` varchar(100) DEFAULT 'fas fa-star',
  `is_featured` tinyint(1) DEFAULT 0,
  `status` varchar(20) DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image` varchar(255) DEFAULT 'default-activity.jpg'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`id`, `name`, `category`, `description`, `price`, `price_unit`, `price_note`, `duration`, `duration_unit`, `length_value`, `length`, `length_unit`, `icon`, `is_featured`, `status`, `created_at`, `updated_at`, `image`) VALUES
(1, 'Banana Boat', 'Water Activities', 'Fun banana boat ride for the whole family. Hold on tight as you bounce across the waves!', 270.00, 'per person', '', NULL, NULL, NULL, '', 'meters', 'fas fa-water', 1, 'available', '2026-09-10 05:05:17', '2026-09-10 05:29:20', '1789018160_Banana_Boat.jfif'),
(2, 'Hurricane', 'Water Activities', 'Thrilling hurricane ride that spins and bounces across the water. Perfect for groups looking for adventure!', 1800.00, 'per ride', 'Good for 3-6 people', NULL, 'minutes', NULL, NULL, 'meters', 'fas fa-hurricane', 0, 'available', '2026-09-10 05:05:17', '2026-09-29 11:54:19', '1789023672_Hurricane.jfif'),
(3, 'Jet Ski', 'Water Sports', 'Ride the waves with our high-performance jet skis. Experience the thrill of speed on the water!', 1500.00, '15 minutes', 'Also available: ₱3,000 for 30 mins, ₱5,000 for 1 hour', NULL, 'minutes', NULL, NULL, 'meters', 'fas fa-ship', 0, 'available', '2026-09-10 05:05:17', '2026-09-29 11:53:58', '1789024004_Jet_Ski.png'),
(4, 'Water Bike', 'Water Activities', 'Pedal your way across the water on our stable and fun water bikes. Great for all ages!', 350.00, '30 minutes', '', NULL, NULL, NULL, NULL, NULL, 'fas fa-bicycle', 0, 'available', '2026-09-10 05:05:17', '2026-09-10 07:05:05', '1789023905_Water_Bike.jpg'),
(5, 'Snorkeling', 'Water Activities', 'Discover the vibrant marine life and coral reefs beneath the crystal-clear waters of Hundred Islands.', 200.00, 'per set', 'Includes life vest, mask, and snorkel', NULL, 'minutes', NULL, NULL, 'meters', 'fas fa-mask', 0, 'available', '2026-09-10 05:05:17', '2026-09-29 11:54:08', '1789023823_Snorkeling.jpg'),
(6, 'Helmet Diving', 'Water Activities', 'Walk on the ocean floor with our professional helmet diving experience. No swimming skills required!', 400.00, 'per person', '', NULL, 'minutes', NULL, '', 'meters', 'fas fa-hard-hat', 0, 'available', '2026-09-10 05:05:17', '2026-09-29 11:54:28', '1789018265_Helmet_Diving.jpg'),
(7, 'Kayaking', 'Water Activities', 'Paddle through the mangroves and hidden lagoons at your own pace. Explore the islands up close!', 250.00, 'per hour', 'Good for 2 people', NULL, NULL, NULL, '', 'meters', 'fas fa-person-swimming', 0, 'available', '2026-09-10 05:05:17', '2026-09-10 05:28:37', '1789018117_Kayaking.jfif'),
(8, 'Zipline', 'Adventure', 'Soar through the air on our 120-meter zipline with stunning views of the islands and ocean below.', 100.00, 'per person', 'Short ride - great for beginners', NULL, NULL, NULL, '', 'meters', 'fas fa-cable-car', 0, 'available', '2026-09-10 05:05:17', '2026-09-10 05:28:24', '1789018104_Zipline.jfif');

-- --------------------------------------------------------

--
-- Table structure for table `activity_bookings`
--

CREATE TABLE `activity_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `booking_date` date NOT NULL,
  `number_of_people` int(11) DEFAULT 1,
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `special_requests` text DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'pending',
  `booking_status` varchar(20) DEFAULT 'confirmed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_variations`
--

CREATE TABLE `activity_variations` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `length_value` decimal(10,2) DEFAULT NULL,
  `length_unit` varchar(20) DEFAULT 'meters',
  `price` decimal(10,2) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_blocked_dates`
--

CREATE TABLE `bak_2026_10_blocked_dates` (
  `id` int(11) NOT NULL,
  `item_type` enum('house','tour','food') NOT NULL,
  `item_id` int(11) NOT NULL,
  `block_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `block_type` enum('walk_in','maintenance','special_occasion','owner_use','other') DEFAULT 'walk_in',
  `blocked_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_blocked_dates`
--

INSERT INTO `bak_2026_10_blocked_dates` (`id`, `item_type`, `item_id`, `block_date`, `reason`, `block_type`, `blocked_by`, `created_at`) VALUES
(1, 'house', 4, '2026-10-14', NULL, 'walk_in', 1, '2026-09-29 06:21:32'),
(2, 'house', 4, '2026-10-15', NULL, 'walk_in', 1, '2026-09-29 06:21:32'),
(3, 'house', 4, '2026-10-16', NULL, 'walk_in', 1, '2026-09-29 06:21:32');

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_food_bookings`
--

CREATE TABLE `bak_2026_10_food_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `food_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `reservation_reference` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `size_variant` varchar(100) DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `number_of_persons` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `special_requests` text DEFAULT NULL,
  `booking_date` date NOT NULL,
  `booking_status` enum('pending','confirmed','completed','cancelled') DEFAULT 'pending',
  `payment_status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_notes` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `feedback_rating` int(11) DEFAULT NULL,
  `feedback_text` text DEFAULT NULL,
  `feedback_date` datetime DEFAULT NULL,
  `feedback_is_anonymous` tinyint(4) DEFAULT 0,
  `gcash_reference` varchar(30) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `fulfillment_method` varchar(20) DEFAULT 'pickup',
  `delivery_address` text DEFAULT NULL,
  `package_reference` varchar(50) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_food_bookings`
--

INSERT INTO `bak_2026_10_food_bookings` (`id`, `guest_id`, `guest_name`, `food_id`, `reference_number`, `reservation_reference`, `quantity`, `size_variant`, `preferred_date`, `preferred_time`, `number_of_persons`, `total_amount`, `special_requests`, `booking_date`, `booking_status`, `payment_status`, `payment_proof`, `proof_uploaded_at`, `paid_at`, `reject_reason`, `reject_notes`, `rejected_by`, `rejected_at`, `created_at`, `feedback_rating`, `feedback_text`, `feedback_date`, `feedback_is_anonymous`, `gcash_reference`, `cancelled_at`, `cancellation_reason`, `contact_number`, `fulfillment_method`, `delivery_address`, `package_reference`, `package_id`, `completed_at`) VALUES
(1, 4, 'John Paul Onia Navarro', 1, 'FOOD-20260910-4498', NULL, 1, 'Small', '2026-09-25', '14:00:00', NULL, 1500.00, 'sgdfg', '0000-00-00', 'completed', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-10 17:12:57', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'pickup', NULL, NULL, NULL, NULL),
(2, 4, 'John Paul Onia Navarro', 1, 'FOOD-20260911-8206', NULL, 1, 'Small', '2026-09-26', '09:00:00', NULL, 1500.00, 'extra rice', '0000-00-00', 'completed', 'paid', '1789138248_FOOD-20260911-8206.png', '2026-09-11 09:50:48', '2026-09-11 09:58:36', NULL, NULL, NULL, NULL, '2026-09-11 14:50:00', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'pickup', NULL, NULL, NULL, NULL),
(3, 5, 'Rinn O Yoshida', 3, 'FOOD-20260922-1499', NULL, 1, 'Small', '2026-09-24', '07:00:00', NULL, 4000.00, 'wala', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22 08:08:29', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:47', NULL, NULL, 'pickup', NULL, NULL, NULL, NULL),
(5, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-9C97-F', NULL, 1, 'Small', '2026-10-15', '08:00:00', NULL, 1500.00, 'no spicy', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 11:54:33', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:38', NULL, '+639505241711', 'pickup', NULL, NULL, 1, NULL),
(6, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-BB5E-F', NULL, 1, 'Small', '2026-10-22', '09:00:00', NULL, 1500.00, 'asdfasdf', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:01:57', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:30', NULL, '+639505241711', 'pickup', NULL, NULL, 2, NULL),
(7, 5, 'Rinn O Yoshida', 3, 'FOOD-20260926-7147', NULL, 1, 'Small', '2026-10-22', '09:00:00', NULL, 4000.00, 'afdsdg', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:04:02', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:25', NULL, '+639505241711', 'pickup', NULL, NULL, NULL, NULL),
(8, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-5FBC-F', NULL, 1, 'Small', '2026-09-29', '10:00:00', NULL, 1500.00, 'sdgfsdfgsdfg', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:14:50', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:20', NULL, '+639505241711', 'pickup', NULL, NULL, 3, NULL),
(9, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-AAD9-F', NULL, 1, 'Small', '2026-10-02', '12:00:00', NULL, 4000.00, 'zsfdsdf', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:16:06', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:14', NULL, '+639505241711', 'pickup', NULL, NULL, 4, NULL),
(10, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-E2D4-F', NULL, 1, 'Small', '2026-09-27', '08:00:00', NULL, 1500.00, 'asdfasdf', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:32:58', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:09', NULL, '+639505241711', 'pickup', NULL, NULL, 5, NULL),
(11, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-8C83-F', NULL, 1, 'Small', '2026-10-01', '13:00:00', NULL, 4000.00, 'ghhfghfgh', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:46:54', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:00', NULL, '+639505241711', 'pickup', NULL, NULL, 6, NULL),
(12, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-AB28-F', NULL, 1, 'Small', '2026-09-30', '16:00:00', NULL, 4000.00, 'fdgdfg', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:19:17', NULL, NULL, NULL, 0, NULL, '2026-09-27 08:29:04', NULL, '+639505241711', 'pickup', NULL, NULL, 19, NULL),
(13, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-EB35-F', NULL, 1, 'Small', '2026-09-29', '11:00:00', NULL, 4000.00, '', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:47:07', NULL, NULL, NULL, 0, NULL, '2026-09-27 08:28:59', NULL, '+639505241711', 'pickup', NULL, NULL, 21, NULL),
(14, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-FB6D-F', NULL, 1, 'Small', '2026-09-30', '08:00:00', NULL, 1500.00, '', '0000-00-00', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 15:19:56', NULL, NULL, NULL, 0, NULL, '2026-09-27 08:28:54', NULL, '+639505241711', 'pickup', NULL, NULL, 23, NULL),
(15, 5, 'Rinn O Yoshida', 1, 'PKG-20260927-741F-F', NULL, 1, 'Small', '2026-09-30', '08:00:00', NULL, 1500.00, 'asdasd', '0000-00-00', 'completed', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 01:18:46', NULL, NULL, NULL, 0, NULL, NULL, NULL, '+639505241711', 'pickup', NULL, NULL, 24, NULL),
(16, 5, 'Rinn O Yoshida', 1, 'PKG-20260927-4CAA-F', NULL, 1, 'Small', '2026-09-28', '11:00:00', NULL, 1500.00, 'adsasd', '0000-00-00', 'completed', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 07:48:52', NULL, NULL, NULL, 0, NULL, NULL, NULL, '+639505241711', 'pickup', NULL, NULL, 25, NULL),
(17, 5, 'Rinn O Yoshida', 1, 'PKG-20260928-F864-F', NULL, 1, 'Small', '2026-10-21', '09:00:00', NULL, 1500.00, 'wala', '0000-00-00', 'pending', 'paid', NULL, NULL, '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, '2026-09-28 21:47:13', NULL, NULL, NULL, 0, NULL, NULL, NULL, '+639385934075', 'pickup', NULL, NULL, 26, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_food_items`
--

CREATE TABLE `bak_2026_10_food_items` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` enum('breakfast','lunch','dinner','snack','beverage') NOT NULL,
  `image` varchar(255) DEFAULT 'default-food.jpg',
  `is_available` tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `inclusions` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `price_original` decimal(10,2) DEFAULT NULL,
  `size_variations` text DEFAULT NULL,
  `gallery_images` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_food_items`
--

INSERT INTO `bak_2026_10_food_items` (`id`, `name`, `description`, `price`, `category`, `image`, `is_available`, `is_featured`, `inclusions`, `created_at`, `updated_at`, `price_original`, `size_variations`, `gallery_images`) VALUES
(1, 'BILAO PACKAGES', 'Enjoy our food bilao with a mixed seafood, grilled pork or chicken, and Alaminos best garlic longganisa, served with rice and drinks. 🥓🍣', 1500.00, '', '1788018838_BILAO_PACKAGES.jfif', 1, 0, 'Grilled Liempo\r\nGrilled Pusit\r\nBoneless Bangus\r\nAlaminos Longganisa\r\nButtered Shrimps\r\nOkra & Talong\r\nFried Sara\r\nRice\r\nSawsawan\r\nSoftdrinks\r\nUtensils', '2026-08-29 10:53:58', NULL, NULL, '[{\"size\":\"Small\",\"pax\":\"2-5 PAX\",\"price\":1500},{\"size\":\"Medium\",\"pax\":\"5-8 PAX\",\"price\":2000},{\"size\":\"Large\",\"pax\":\"8-12 PAX\",\"price\":3000},{\"size\":\"XLarge\",\"pax\":\"12-15 PAX\",\"price\":3500}]', NULL),
(3, 'BOODLE FIGHT', 'Enjoy our boodle fight special with grilled liempo, grilled pusit, boneless bangus, Alaminos longganisa, seafood, fresh vegetables, fruits, rice, and drinks, perfect for sharing with family and friends. 🥓🦐🍚', 4000.00, '', '1789137446_BOODLE_FIGHT.jfif', 1, 0, 'Grilled Liempo\r\nGrilled Pusit\r\nBoneless Bangus\r\nAlaminos Longanisa\r\nButtered Shrimps\r\nCrabs\r\nTahong\r\nOkra\r\nTalong\r\nSaba & Fruits\r\nRice\r\nSawsawan\r\nSoftdrinks', '2026-09-11 09:37:26', NULL, NULL, '[{\"size\":\"Small\",\"pax\":\"6\",\"price\":4000},{\"size\":\"Medium\",\"pax\":\"10\",\"price\":5500},{\"size\":\"Large\",\"pax\":\"15\",\"price\":7500},{\"size\":\"Special\",\"pax\":\"20\",\"price\":9000}]', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_house_bookings`
--

CREATE TABLE `bak_2026_10_house_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `house_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_in_time` time DEFAULT '14:00:00',
  `check_out_date` date NOT NULL,
  `check_out_time` time DEFAULT '12:00:00',
  `number_of_guests` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `special_requests` text DEFAULT NULL,
  `payment_status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `booking_status` enum('confirmed','cancelled','completed') DEFAULT 'confirmed',
  `completed_at` datetime DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_notes` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `feedback_is_anonymous` tinyint(1) DEFAULT 0,
  `rebook_count` int(11) NOT NULL DEFAULT 0,
  `previous_check_in_date` date DEFAULT NULL,
  `previous_check_out_date` date DEFAULT NULL,
  `previous_number_of_guests` int(11) DEFAULT NULL,
  `previous_total_amount` decimal(10,2) DEFAULT NULL,
  `rebook_confirmed_at` datetime DEFAULT NULL,
  `rebooked_at` datetime DEFAULT NULL,
  `original_booking_id` int(11) DEFAULT NULL,
  `booking_date` datetime NOT NULL DEFAULT current_timestamp(),
  `guest_names` text DEFAULT NULL,
  `gcash_reference` varchar(30) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `package_reference` varchar(50) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_house_bookings`
--

INSERT INTO `bak_2026_10_house_bookings` (`id`, `guest_id`, `house_id`, `reference_number`, `check_in_date`, `check_in_time`, `check_out_date`, `check_out_time`, `number_of_guests`, `total_amount`, `special_requests`, `payment_status`, `booking_status`, `completed_at`, `payment_proof`, `proof_uploaded_at`, `paid_at`, `reject_reason`, `reject_notes`, `rejected_by`, `rejected_at`, `created_at`, `feedback_is_anonymous`, `rebook_count`, `previous_check_in_date`, `previous_check_out_date`, `previous_number_of_guests`, `previous_total_amount`, `rebook_confirmed_at`, `rebooked_at`, `original_booking_id`, `booking_date`, `guest_names`, `gcash_reference`, `cancelled_at`, `cancellation_reason`, `package_reference`, `package_id`) VALUES
(14, 4, 4, 'HS-20260814-4537', '2026-09-15', '14:00:00', '2026-09-17', '12:00:00', 20, 11000.00, NULL, 'cancelled', 'cancelled', NULL, '1786702958_HS-20260814-4537.png', '2026-08-14 05:22:38', '2026-08-14 05:51:06', NULL, NULL, NULL, NULL, '2026-08-14 10:22:20', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-14 05:22:20', 'gian\r\nfaye\r\nedwin\r\ngids\r\nedward\r\nandrew\r\nrainier\r\nyoanna\r\njhoncel\r\npatrick\r\npot\r\nsilver\r\nace\r\nraiden\r\nbronya\r\nsonya\r\nmouse\r\nkeyboard\r\nmonitor\r\ncellphone', NULL, NULL, NULL, NULL, NULL),
(15, 4, 4, 'HS-20260816-4212', '2026-09-16', '14:00:00', '2026-09-18', '12:00:00', 15, 11000.00, NULL, 'cancelled', 'cancelled', NULL, '1786911581_HS-20260816-4212.png', '2026-08-16 15:19:41', NULL, NULL, NULL, NULL, NULL, '2026-08-16 20:19:22', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-16 15:19:22', 'giana gian\r\nentoy entoy\r\nfaye faye\r\nyoanna yoanna\r\njhoncel jhoncel\r\npat trick\r\nlen odi\r\ncell phone\r\ntedi bear\r\nda mit\r\nmo use\r\nsa patos\r\nka ma\r\nil aw\r\nun an\r\nper fume', NULL, NULL, NULL, NULL, NULL),
(16, 4, 4, 'HS-20260816-2228', '2026-09-16', '14:00:00', '2026-09-18', '12:00:00', 10, 11000.00, NULL, 'paid', 'cancelled', NULL, '1786912289_HS-20260816-2228.png', '2026-08-16 15:31:29', '2026-08-16 15:49:41', NULL, NULL, NULL, NULL, '2026-08-16 20:31:15', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-16 15:31:15', 'gia na\r\nfa ye\r\nen toy\r\nyoa nna\r\njhon cel\r\npat rick\r\nle nodi\r\ntedi bear\r\nu nan\r\ncell phon\r\nda mit', NULL, '2026-09-27 14:03:11', 'Booking dates no longer available', NULL, NULL),
(17, 4, 4, 'HS-20260831-7897', '2026-09-28', '14:00:00', '2026-09-30', '12:00:00', 12, 11000.00, NULL, 'paid', 'cancelled', NULL, '1788186425_HS-20260831-7897.png', '2026-08-31 09:27:06', '2026-08-31 10:02:22', NULL, NULL, NULL, NULL, '2026-08-31 14:26:19', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-31 09:26:19', 'try1\r\ntry2\r\ntry3\r\ntry4\r\ntry5\r\ntry6\r\ntry7\r\ntry8\r\ntry9\r\ntry10\r\ntry11\r\ntry12', NULL, '2026-09-27 14:15:11', 'Guest requested cancellation', NULL, NULL),
(18, 4, 4, 'HS-20260903-7958', '2026-11-10', '14:00:00', '2026-11-12', '12:00:00', 8, 11000.00, NULL, 'paid', 'cancelled', NULL, '1788395025_HS-20260903-7958.png', '2026-09-02 19:23:45', '2026-09-02 19:24:30', NULL, NULL, NULL, NULL, '2026-09-03 00:22:35', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-02 19:22:35', 'user1\r\nuser2\r\nuser3\r\nuser4\r\nuser5\r\nuser6\r\nuser7\r\nuser8\r\nuser9\r\nuser10', NULL, '2026-09-27 14:12:35', 'Guest requested cancellation', NULL, NULL),
(19, 4, 4, 'HS-20260907-4031', '2026-10-13', '14:00:00', '2026-10-16', '12:00:00', 10, 16500.00, NULL, 'cancelled', 'cancelled', NULL, '1788790909_HS-20260907-4031.png', '2026-09-07 09:21:49', NULL, NULL, NULL, NULL, NULL, '2026-09-07 14:20:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 09:20:54', 'user1\r\nuser2\r\nuser3\r\nuser4\r\nuser5\r\nuser6\r\nuser7\r\nuser8\r\nuser9\r\nuser10', NULL, NULL, NULL, NULL, NULL),
(20, 4, 4, 'HS-20260907-1136', '2026-10-12', '14:00:00', '2026-10-15', '12:00:00', 10, 16500.00, NULL, 'cancelled', 'cancelled', NULL, '1788793238_HS-20260907-1136.png', '2026-09-07 10:00:38', NULL, NULL, NULL, NULL, NULL, '2026-09-07 14:58:22', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 09:58:22', '1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8\r\n9\r\n10', NULL, NULL, NULL, NULL, NULL),
(21, 4, 4, 'HS-20260907-4480', '2026-10-20', '14:00:00', '2026-10-22', '12:00:00', 11, 11000.00, NULL, 'cancelled', 'cancelled', NULL, '1788794258_HS-20260907-4480.png', '2026-09-07 10:17:38', NULL, NULL, NULL, NULL, NULL, '2026-09-07 15:16:39', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 10:16:39', '1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8\r\n9\r\n10\r\n11', NULL, NULL, NULL, NULL, NULL),
(22, 4, 4, 'HS-20260907-5077', '2026-09-23', '14:00:00', '2026-09-25', '12:00:00', 5, 11000.00, NULL, 'cancelled', 'cancelled', NULL, '1788798004_HS-20260907-5077.png', '2026-09-07 11:20:04', NULL, NULL, NULL, NULL, NULL, '2026-09-07 15:23:58', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 10:23:58', '1\r\n2\r\n3\r\n4\r\n5', NULL, NULL, NULL, NULL, NULL),
(23, 4, 4, 'HS-20260907-9985', '2026-09-19', '14:00:00', '2026-09-20', '12:00:00', 3, 5500.00, NULL, 'pending', 'cancelled', NULL, '1788798217_HS-20260907-9985.png', '2026-09-07 11:23:37', NULL, NULL, NULL, NULL, NULL, '2026-09-07 16:23:09', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 11:23:09', '1\r\n2\r\n3', NULL, '2026-09-27 14:14:03', 'Guest requested cancellation', NULL, NULL),
(24, 4, 4, 'HS-20260908-2990', '2026-09-24', '14:00:00', '2026-09-26', '12:00:00', 6, 11000.00, NULL, 'paid', 'cancelled', NULL, '1788853728_HS-20260908-2990.png', '2026-09-08 02:48:48', '2026-09-27 14:14:36', NULL, NULL, NULL, NULL, '2026-09-08 05:58:41', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 00:58:41', '1\r\n2\r\n3\r\n4\r\n5\r\n6', NULL, '2026-09-27 14:15:24', 'Guest requested cancellation', NULL, NULL),
(25, 4, 4, 'HS-20260908-1012', '2026-09-22', '14:00:00', '2026-09-23', '12:00:00', 6, 5500.00, NULL, 'paid', 'completed', NULL, '1788853939_HS-20260908-1012.png', '2026-09-08 02:52:19', '2026-09-08 03:33:21', NULL, NULL, NULL, NULL, '2026-09-08 07:51:56', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 02:51:56', '1\r\n2\r\n3\r\n4\r\n5\r\n6', NULL, NULL, NULL, NULL, NULL),
(26, 4, 4, 'HS-20260908-5828', '2026-10-13', '14:00:00', '2026-10-15', '12:00:00', 8, 11000.00, NULL, 'cancelled', 'cancelled', NULL, '1788856794_HS-20260908-5828.png', '2026-09-08 03:39:54', '2026-09-08 03:44:20', NULL, NULL, NULL, NULL, '2026-09-08 08:39:21', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 03:39:21', '1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8', NULL, NULL, NULL, NULL, NULL),
(27, 4, 6, 'HS-20260911-4085', '2026-09-22', '14:00:00', '2026-09-24', '12:00:00', 8, 32000.00, NULL, 'paid', 'completed', NULL, '1789137891_HS-20260911-4085.png', '2026-09-11 09:44:51', '2026-09-11 09:56:44', NULL, NULL, NULL, NULL, '2026-09-11 14:43:51', 0, 2, NULL, NULL, NULL, NULL, '2026-09-14 22:39:22', '2026-09-14 07:59:36', NULL, '2026-09-11 09:43:51', 'gian\r\nfaye\r\nentoy\r\nyoanna\r\njhoncel\r\npatrick \r\nandrew\r\naugust', NULL, NULL, NULL, NULL, NULL),
(28, 4, 6, 'RE-20260911-2535', '2026-10-22', '14:00:00', '2026-10-24', '12:00:00', 1, 4000.00, NULL, 'paid', '', NULL, '1789139286_RE-20260911-2535.png', '2026-09-11 10:08:06', '2026-09-11 10:09:04', NULL, NULL, NULL, NULL, '2026-09-11 15:07:47', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, 27, '2026-09-11 10:07:47', NULL, NULL, NULL, NULL, NULL, NULL),
(29, 4, 5, 'HS-20260914-3630', '2026-09-23', '14:00:00', '2026-09-25', '12:00:00', 10, 120000.00, NULL, 'paid', 'completed', NULL, '1789411226_HS-20260914-3630.png', '2026-09-14 13:40:26', '2026-09-14 13:59:56', NULL, NULL, NULL, NULL, '2026-09-14 18:39:57', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-14 13:39:57', 'gian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', NULL, NULL, NULL, NULL, NULL),
(32, 5, 5, 'HS-20260915-9520', '2026-09-29', '14:00:00', '2026-09-30', '12:00:00', 10, 6000.00, NULL, 'paid', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-14 01:51:47', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-15 12:12:52', 'gian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', NULL, '2026-09-29 23:01:17', 'Guest requested cancellation', NULL, NULL),
(33, 5, 6, 'HS-20260916-6124', '2026-09-25', '14:00:00', '2026-09-26', '12:00:00', 10, 2000.00, NULL, 'paid', 'completed', NULL, '1789521247_HS-20260916-6124.png', '2026-09-16 09:14:07', '2026-09-16 09:25:30', NULL, NULL, NULL, NULL, '2026-09-16 01:00:47', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-16 09:00:47', 'gian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', '0045077587865', NULL, NULL, NULL, NULL),
(35, 5, 5, 'HS-20260923-8791', '2026-10-29', '14:00:00', '2026-10-31', '12:00:00', 5, 12000.00, NULL, 'pending', 'cancelled', NULL, '1790671505_HS-20260923-8791.png', '2026-09-29 16:45:05', NULL, NULL, NULL, NULL, NULL, '2026-09-23 06:25:47', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-23 14:25:47', 'gian\nentoy\ndaye\nfaye\ngust', '23423324234234234234', '2026-09-29 14:27:54', 'Guest requested cancellation', NULL, NULL),
(36, 5, 5, 'HS-20260923-2571', '2026-10-22', '14:00:00', '2026-10-23', '12:00:00', 2, 12000.00, NULL, 'paid', 'confirmed', NULL, '1790215789_HS-20260923-2571.png', '2026-09-24 10:09:49', '2026-09-24 10:40:19', NULL, NULL, NULL, NULL, '2026-09-23 06:26:28', 0, 1, '2026-10-23', '2026-10-24', 2, 6000.00, '2026-09-24 13:51:18', '2026-09-24 13:51:06', NULL, '2026-09-23 14:26:28', 'john\npaul', '0045077587865', NULL, NULL, NULL, NULL),
(38, 5, 6, 'PKG-20260926-9C97-H', '2026-10-15', '14:00:00', '2026-10-17', '12:00:00', 10, 4000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 11:54:33', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 19:54:33', 'kanon\nrinn\nchika\nfreiren\nshibuya\nkyoto\nmaro\nshinigami\ntensie\nshinra', NULL, '2026-09-26 20:48:34', NULL, NULL, 1),
(39, 5, 4, 'PKG-20260926-BB5E-H', '2026-10-22', '14:00:00', '2026-10-23', '12:00:00', 4, 5500.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:01:57', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:01:57', 'sdfsdf\nsdfsdf\nasdfsdf\nsdfsdfa', NULL, '2026-09-26 20:48:31', NULL, NULL, 2),
(40, 5, 6, 'PKG-20260926-5FBC-H', '2026-09-28', '14:00:00', '2026-09-30', '12:00:00', 5, 4000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:14:50', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:14:50', 'sdfsdf\nsdafsdfsdfs\nsdfasdfsd\nasdfsdfasdf\nsdfasdfds', NULL, '2026-09-26 20:48:26', NULL, NULL, 3),
(41, 5, 6, 'PKG-20260926-AAD9-H', '2026-10-01', '14:00:00', '2026-10-02', '12:00:00', 4, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:16:06', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:16:06', 'asdf\nasasdf\nasdfsdf\nsdfasdfas', NULL, '2026-09-26 20:48:23', NULL, NULL, 4),
(42, 5, 5, 'PKG-20260926-E2D4-H', '2026-10-15', '14:00:00', '2026-10-17', '12:00:00', 5, 12000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:32:58', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:32:58', 'sdfs\nasdfasdf\nasdfsdf\nadsfasdf\nsdfasdf', NULL, '2026-09-26 20:48:20', NULL, NULL, 5),
(43, 5, 6, 'PKG-20260926-8C83-H', '2026-10-03', '14:00:00', '2026-10-04', '12:00:00', 4, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:46:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:46:54', 'sdfsfdsfg\nsdfgdfgdf\nssdfsdfsd\nsdfsdfsdf', NULL, '2026-09-26 20:47:52', NULL, NULL, 6),
(44, 5, 6, 'PKG-20260926-F8E2-H', '2026-09-27', '14:00:00', '2026-09-28', '12:00:00', 2, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:49:05', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:49:05', 'sdfgsdfg\nsdfgdsfg', NULL, '2026-09-26 22:13:31', NULL, NULL, 7),
(45, 5, 6, 'PKG-20260926-7F11-H', '2026-09-29', '14:00:00', '2026-09-30', '12:00:00', 2, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:49:38', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:49:38', 'xdfg\ndfgdf', NULL, '2026-09-26 22:13:27', NULL, NULL, 8),
(46, 5, 6, 'PKG-20260926-02DD-H', '2026-10-29', '14:00:00', '2026-10-30', '12:00:00', 2, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:50:03', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:50:03', 'ette\nteer', NULL, '2026-09-26 22:13:24', NULL, NULL, 9),
(47, 5, 5, 'PKG-20260926-16E3-H', '2026-09-27', '14:00:00', '2026-09-28', '12:00:00', 2, 6000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:50:31', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:50:31', 'yrtyrty\nrtyrty', NULL, '2026-09-26 22:13:21', NULL, NULL, 10),
(48, 5, 6, 'PKG-20260926-0960-H', '2026-10-01', '14:00:00', '2026-10-02', '12:00:00', 2, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:50:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:50:54', 'dd\ndfgdfg', NULL, '2026-09-26 21:22:08', NULL, NULL, 11),
(49, 5, 6, 'PKG-20260926-6CA2-H', '2026-10-03', '14:00:00', '2026-10-04', '12:00:00', 2, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:51:24', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:51:24', 'fgdfg\ndfgdfg', NULL, '2026-09-26 21:22:05', NULL, NULL, 12),
(50, 5, 5, 'PKG-20260926-F6B8-H', '2026-10-01', '14:00:00', '2026-10-02', '12:00:00', 2, 6000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 13:05:07', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:05:07', 'zsdfsd\nszdfsdf', NULL, '2026-09-26 21:22:02', NULL, NULL, 13),
(51, 5, 6, 'PKG-20260926-8B41-H', '2026-10-08', '14:00:00', '2026-10-09', '12:00:00', 2, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 13:05:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:05:54', 'sdfsdf\nsdfsdf', NULL, '2026-09-26 21:07:05', NULL, NULL, 16),
(52, 5, 5, 'PKG-20260926-F39F-H', '2026-10-09', '14:00:00', '2026-10-10', '12:00:00', 2, 6000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 13:18:37', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:18:37', 'fsdfsdf\nsdfsdf', NULL, '2026-09-26 21:21:58', NULL, NULL, 17),
(53, 5, 5, 'PKG-20260926-5633-H', '2026-09-26', '14:00:00', '2026-09-27', '12:00:00', 2, 6000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:17:48', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 22:17:48', 'sfdsf\nsdfsdf', NULL, '2026-09-27 08:28:39', NULL, NULL, 18),
(54, 5, 5, 'PKG-20260926-AB28-H', '2026-10-15', '14:00:00', '2026-10-16', '12:00:00', 2, 6000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:19:17', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 22:19:17', 'sdfds\nsdfsdf', NULL, '2026-09-27 08:28:35', NULL, NULL, 19),
(55, 5, 6, 'PKG-20260926-EB35-H', '2026-09-28', '14:00:00', '2026-09-29', '12:00:00', 4, 2000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:47:07', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 22:47:07', 'dasdad\nasdasdas\nasdasda\nasdasdas', NULL, '2026-09-27 08:28:32', NULL, NULL, 21),
(56, 5, 5, 'PKG-20260926-FB6D-H', '2026-10-02', '14:00:00', '2026-10-03', '12:00:00', 2, 6000.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 15:19:56', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 23:19:56', 'ggffg\nfgxhf', NULL, '2026-09-27 08:28:28', NULL, NULL, 23),
(57, 5, 5, 'PKG-20260927-741F-H', '2026-09-27', '14:00:00', '2026-09-28', '12:00:00', 2, 6000.00, NULL, 'pending', 'completed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 01:18:46', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 09:18:46', 'asdasd\nasdasd', NULL, NULL, NULL, NULL, 24),
(58, 5, 6, 'PKG-20260927-4CAA-H', '2026-10-29', '14:00:00', '2026-10-30', '12:00:00', 3, 2000.00, NULL, 'pending', 'confirmed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 07:48:52', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 15:48:52', 'sdfdsf\nsdfsdf\nsdfsdf', NULL, NULL, NULL, NULL, 25),
(59, 5, 4, 'PKG-20260928-F864-H', '2026-10-29', '07:00:00', '2026-10-31', '18:00:00', 5, 11000.00, NULL, 'paid', '', NULL, NULL, NULL, '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, '2026-09-28 21:47:13', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-29 05:47:13', 'asdasdas\nasdasd\nasdasd\nasdasd\nasdasd', NULL, NULL, NULL, NULL, 26),
(60, 5, 4, 'HS-20261002-2181', '2026-10-28', '14:00:00', '2026-10-30', '12:00:00', 1, 11000.00, NULL, 'pending', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 04:57:42', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 12:57:42', 'sadasdas', NULL, NULL, NULL, NULL, NULL),
(61, 5, 4, 'HS-20261006-1908', '2026-10-22', '05:00:00', '2026-10-24', '12:00:00', 1, 11000.00, NULL, 'pending', '', NULL, '1791303272_HS-20261006-1908.png', '2026-10-06 12:14:32', NULL, NULL, NULL, NULL, NULL, '2026-10-06 16:13:52', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-06 12:13:52', 'ahahah', '23423324234234234234', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_package_bookings`
--

CREATE TABLE `bak_2026_10_package_bookings` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `house_booking_id` int(11) DEFAULT NULL,
  `tour_booking_id` int(11) DEFAULT NULL,
  `food_booking_id` int(11) DEFAULT NULL,
  `items_json` longtext DEFAULT NULL,
  `house_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tour_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `food_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `contact_number` varchar(20) DEFAULT NULL,
  `payment_status` enum('pending','paid','refunded') NOT NULL DEFAULT 'pending',
  `booking_status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `special_requests` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `gcash_reference` varchar(30) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_package_bookings`
--

INSERT INTO `bak_2026_10_package_bookings` (`id`, `reference_number`, `guest_id`, `house_booking_id`, `tour_booking_id`, `food_booking_id`, `items_json`, `house_amount`, `tour_amount`, `food_amount`, `grand_total`, `contact_number`, `payment_status`, `booking_status`, `special_requests`, `created_at`, `updated_at`, `gcash_reference`, `payment_proof`, `proof_uploaded_at`, `cancelled_at`, `completed_at`) VALUES
(1, 'PKG-20260926-9C97', 5, 38, 14, 5, NULL, 4000.00, 3000.00, 1500.00, 8500.00, '+639505241711', 'pending', 'cancelled', 'Food: no spicy\nTour: safe ride', '2026-09-26 19:54:33', '2026-09-26 20:48:16', NULL, NULL, NULL, '2026-09-26 20:48:16', NULL),
(2, 'PKG-20260926-BB5E', 5, 39, NULL, 6, NULL, 5500.00, 0.00, 1500.00, 7000.00, '+639505241711', 'pending', 'cancelled', 'Food: asdfasdf', '2026-09-26 20:01:57', '2026-09-26 20:48:09', NULL, NULL, NULL, '2026-09-26 20:48:09', NULL),
(3, 'PKG-20260926-5FBC', 5, 40, NULL, 8, NULL, 4000.00, 0.00, 1500.00, 5500.00, '+639505241711', 'pending', 'cancelled', 'Food: sdgfsdfgsdfg', '2026-09-26 20:14:50', '2026-09-26 20:48:04', NULL, NULL, NULL, '2026-09-26 20:48:04', NULL),
(4, 'PKG-20260926-AAD9', 5, 41, NULL, 9, NULL, 2000.00, 0.00, 4000.00, 6000.00, '+639505241711', 'pending', 'cancelled', 'Food: zsfdsdf', '2026-09-26 20:16:06', '2026-09-26 20:47:58', NULL, NULL, NULL, '2026-09-26 20:47:58', NULL),
(5, 'PKG-20260926-E2D4', 5, 42, NULL, 10, NULL, 12000.00, 0.00, 1500.00, 13500.00, '+639505241711', 'pending', 'cancelled', 'Food: asdfasdf', '2026-09-26 20:32:58', '2026-09-26 20:47:42', NULL, NULL, NULL, '2026-09-26 20:47:42', NULL),
(6, 'PKG-20260926-8C83', 5, 43, NULL, 11, NULL, 2000.00, 0.00, 4000.00, 6000.00, '+639505241711', 'pending', 'cancelled', 'Food: ghhfghfgh', '2026-09-26 20:46:54', '2026-09-26 20:47:19', NULL, NULL, NULL, '2026-09-26 20:47:19', NULL),
(7, 'PKG-20260926-F8E2', 5, 44, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:49:05', '2026-09-26 21:21:50', NULL, NULL, NULL, '2026-09-26 21:21:50', NULL),
(8, 'PKG-20260926-7F11', 5, 45, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:49:38', '2026-09-26 21:21:45', NULL, NULL, NULL, '2026-09-26 21:21:45', NULL),
(9, 'PKG-20260926-02DD', 5, 46, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:50:03', '2026-09-26 21:21:40', NULL, NULL, NULL, '2026-09-26 21:21:40', NULL),
(10, 'PKG-20260926-16E3', 5, 47, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:50:31', '2026-09-26 21:21:35', NULL, NULL, NULL, '2026-09-26 21:21:35', NULL),
(11, 'PKG-20260926-0960', 5, 48, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:50:54', '2026-09-26 21:21:31', NULL, NULL, NULL, '2026-09-26 21:21:31', NULL),
(12, 'PKG-20260926-6CA2', 5, 49, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:51:24', '2026-09-26 21:21:26', NULL, NULL, NULL, '2026-09-26 21:21:26', NULL),
(13, 'PKG-20260926-F6B8', 5, 50, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:07', '2026-09-26 21:21:20', NULL, NULL, NULL, '2026-09-26 21:21:20', NULL),
(14, 'PKG-20260926-52AE', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:12', '2026-09-26 21:07:15', NULL, NULL, NULL, '2026-09-26 21:07:15', NULL),
(15, 'PKG-20260926-1C4E', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:33', '2026-09-26 21:07:10', NULL, NULL, NULL, '2026-09-26 21:07:10', NULL),
(16, 'PKG-20260926-8B41', 5, 51, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:54', '2026-09-26 21:07:02', NULL, NULL, NULL, '2026-09-26 21:07:02', NULL),
(17, 'PKG-20260926-F39F', 5, 52, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:18:37', '2026-09-26 21:21:01', NULL, NULL, NULL, '2026-09-26 21:21:01', NULL),
(18, 'PKG-20260926-5633', 5, 53, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 22:17:48', '2026-09-27 08:29:39', NULL, NULL, NULL, '2026-09-27 08:29:39', NULL),
(19, 'PKG-20260926-AB28', 5, 54, NULL, 12, NULL, 6000.00, 0.00, 4000.00, 10000.00, '+639505241711', 'pending', 'cancelled', 'Food: fdgdfg', '2026-09-26 22:19:17', '2026-09-27 08:29:33', NULL, NULL, NULL, '2026-09-27 08:29:33', NULL),
(20, 'PKG-20260926-173F', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 22:46:10', '2026-09-27 08:29:26', NULL, NULL, NULL, '2026-09-27 08:29:26', NULL),
(21, 'PKG-20260926-EB35', 5, 55, 16, 13, NULL, 2000.00, 3000.00, 4000.00, 9000.00, '+639505241711', 'pending', 'cancelled', 'Tour: asdasda', '2026-09-26 22:47:07', '2026-09-27 08:29:21', NULL, NULL, NULL, '2026-09-27 08:29:21', NULL),
(22, 'PKG-20260926-E5F7', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 23:17:47', '2026-09-27 08:29:15', NULL, NULL, NULL, '2026-09-27 08:29:15', NULL),
(23, 'PKG-20260926-FB6D', 5, 56, NULL, 14, NULL, 6000.00, 0.00, 1500.00, 7500.00, '+639505241711', 'pending', 'cancelled', NULL, '2026-09-26 23:19:56', '2026-09-27 08:29:09', NULL, NULL, NULL, '2026-09-27 08:29:09', NULL),
(24, 'PKG-20260927-741F', 5, 57, 17, 15, NULL, 6000.00, 3000.00, 1500.00, 10500.00, '+639505241711', 'pending', 'cancelled', 'Food: asdasd', '2026-09-27 09:18:46', '2026-09-29 05:48:18', '0045077587865', '1790473953_PKG-20260927-741F.png', '2026-09-27 09:52:33', '2026-09-29 05:48:18', NULL),
(25, 'PKG-20260927-4CAA', 5, 58, NULL, 16, NULL, 2000.00, 0.00, 1500.00, 3500.00, '+639505241711', 'pending', 'pending', 'Food: adsasd', '2026-09-27 15:48:52', '2026-09-27 15:49:25', '0045077587865', '1790495365_PKG-20260927-4CAA.png', '2026-09-27 15:49:25', NULL, NULL),
(26, 'PKG-20260928-F864', 5, 59, 18, 17, NULL, 11000.00, 3000.00, 1500.00, 15500.00, '+639505241711', 'paid', 'confirmed', 'Food: wala\nTour: wala', '2026-09-29 05:47:13', '2026-09-29 05:59:43', '23423324234234234234', '1790632071_PKG-20260928-F864.png', '2026-09-29 05:47:51', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_site_content`
--

CREATE TABLE `bak_2026_10_site_content` (
  `id` int(11) NOT NULL,
  `section_name` varchar(100) NOT NULL,
  `content_key` varchar(100) NOT NULL,
  `content_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_site_content`
--

INSERT INTO `bak_2026_10_site_content` (`id`, `section_name`, `content_key`, `content_value`, `created_at`) VALUES
(1, 'hero', 'title', 'Welcome to Transient House & Tours', '2026-07-09 01:25:44'),
(2, 'hero', 'subtitle', 'Your home away from home and gateway to unforgettable island adventures.', '2026-07-09 01:25:44'),
(3, 'hero', 'stats_houses_label', 'Total Houses', '2026-07-09 01:25:44'),
(4, 'hero', 'stats_houses_available_label', 'Houses Available', '2026-07-09 01:25:44'),
(5, 'hero', 'stats_tours_label', 'Tour Packages', '2026-07-09 01:25:44'),
(6, 'hero', 'stats_tours_available_label', 'Tours Available', '2026-07-09 01:25:44'),
(7, 'features', 'section_title', 'Why Choose Us', '2026-07-09 01:25:44'),
(8, 'features', 'feature1_title', 'Comfortable Houses', '2026-07-09 01:25:44'),
(9, 'features', 'feature1_desc', 'Experience true comfort in our well-appointed transient houses.', '2026-07-09 01:25:44'),
(10, 'features', 'feature2_title', 'Island Tours', '2026-07-09 01:25:44'),
(11, 'features', 'feature2_desc', 'Explore the beautiful islands with our exciting tour packages.', '2026-07-09 01:25:44'),
(12, 'features', 'feature3_title', 'Secure Booking', '2026-07-09 01:25:44'),
(13, 'features', 'feature3_desc', 'Your transactions are safe and secure with our encrypted booking system.', '2026-07-09 01:25:44'),
(14, 'features', 'feature4_title', '24/7 Support', '2026-07-09 01:25:44'),
(15, 'features', 'feature4_desc', 'We\'re always here to help you with any questions or concerns.', '2026-07-09 01:25:44'),
(16, 'cta', 'title', 'Ready to Book Your Stay?', '2026-07-09 01:25:44'),
(17, 'cta', 'subtitle', 'Choose from our comfortable houses or exciting tour packages for your next adventure.', '2026-07-09 01:25:44'),
(18, 'cta', 'button_houses_text', 'Browse Houses', '2026-07-09 01:25:44'),
(19, 'cta', 'button_tours_text', 'Browse Tours', '2026-07-09 01:25:44'),
(20, 'footer', 'company_description', 'Your trusted partner for comfortable accommodations and exciting island adventures.', '2026-07-09 01:25:44'),
(21, 'footer', 'address', ' Inansuana, Lucap, Alaminos, Philippines, 2404', '2026-07-09 01:25:44'),
(22, 'footer', 'phone', ' 0961 838 0969', '2026-07-09 01:25:44'),
(23, 'footer', 'email', 'info@transientrental.com', '2026-07-09 01:25:44'),
(24, 'footer', 'copyright', 'Transient House & Tours. All rights reserved.', '2026-07-09 01:25:44'),
(25, 'footer', 'privacy_policy', 'Privacy Policy', '2026-07-09 01:25:44'),
(26, 'footer', 'terms_of_service', 'Terms of Service', '2026-07-09 01:25:44'),
(27, 'gcash', 'account_name', 'Juan Dela Cruz', '2026-07-09 01:25:44'),
(28, 'gcash', 'number', '09123456789', '2026-07-09 01:25:44'),
(29, 'gcash', 'qr_code', 'gcash_qr.jpg', '2026-07-09 01:25:44'),
(30, 'gcash', 'instructions', '1. Open GCash app\r\n2. Click \'Pay QR\' or \'Scan QR\'\r\n3. Scan the QR code above\r\n4. Enter the exact amount shown\r\n5. Complete the payment\r\n6. Take a screenshot of the transaction\r\n7. Upload screenshot as proof of payment', '2026-07-09 01:25:44'),
(31, 'site_settings', 'logo_path', 'uploads/logos/logo.png', '2026-07-30 04:36:36'),
(32, 'site_settings', 'hero_image_path', 'uploads/hero/hero-bg.jpg', '2026-08-02 01:30:29'),
(33, 'social', 'facebook', 'https://www.facebook.com/share/19ZYXz3q62/', '2026-09-08 11:34:53'),
(34, 'social', 'facebook', 'https://www.facebook.com/share/19ZYXz3q62/', '2026-09-08 11:34:53'),
(35, 'location', 'address', 'Inansuana, Lucap, Alaminos, Philippines, 2404', '2026-09-08 13:10:25'),
(36, 'location', 'google_maps_embed', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d297.1619295453863!2d120.00277185108841!3d16.188971237180805!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3393db0027bc4d99%3A0xcd09fc5b0c6e96b6!2sClarissa%20Dino-Fernandez%20Tour%20Reservation%20and%20Transient%20House!5e1!3m2!1sen!2sph!4v1788872926142!5m2!1sen!2sph\" width=\"600\" height=\"450\" style=\"border:0;\" allowfullscreen=\"\" loading=\"lazy\" referrerpolicy=\"strict-origin-when-cross-origin\">', '2026-09-08 13:10:25'),
(37, 'terms', 'title', 'Terms & Conditions', '2026-09-16 02:15:33'),
(38, 'terms', 'body', 'Welcome to Transient House & Tours. By booking with us, you agree to the following terms:\r\n\r\n1. BOOKING & RESERVATIONS\r\n• All bookings are subject to availability and confirmation.\r\n• A valid government-issued ID is required during check-in.\r\n• The lead guest must be at least 18 years old.\r\n\r\n2. PAYMENT TERMS\r\n• A down payment may be required to confirm your reservation.\r\n• Full payment must be settled before check-in unless otherwise agreed.\r\n• GCash reference numbers must be provided when uploading payment proof.\r\n\r\n3. CANCELLATION & REFUNDS\r\n• Cancellations made 7 days before check-in are eligible for a refund.\r\n• Cancellations within 7 days are non-refundable but may be rebooked once.\r\n• No-shows will be charged the full amount.\r\n\r\n4. HOUSE RULES\r\n• No smoking inside the premises.\r\n• No pets allowed unless pre-approved.\r\n• Keep noise levels reasonable, especially at night.\r\n• Guests are responsible for any damages to property.\r\n\r\n5. TOURS & ACTIVITIES\r\n• Tour schedules may change due to weather or safety concerns.\r\n• Guests must follow all safety instructions from our staff.\r\n• We are not liable for personal items lost during tours.\r\n\r\n6. LIABILITY\r\n• We are not responsible for accidents caused by negligence.\r\n• Guests participate in activities at their own risk.\r\n\r\n7. CHANGES TO TERMS\r\n• We reserve the right to update these terms at any time.\r\n• Continued use of our services means you accept the updated terms.', '2026-09-16 02:15:33'),
(39, 'privacy', 'title', 'Privacy Policy', '2026-09-16 02:15:33'),
(40, 'privacy', 'body', 'Your privacy is important to us. This policy explains what information we collect and how we use it.\r\n\r\n1. INFORMATION WE COLLECT\r\n• Personal details: name, contact number, email, address.\r\n• Government-issued ID for verification.\r\n• Payment proof (GCash reference, screenshots).\r\n• Health information you voluntarily provide (allergies, medical conditions).\r\n\r\n2. HOW WE USE YOUR INFORMATION\r\n• To process bookings and reservations.\r\n• To verify your identity during check-in.\r\n• To contact you regarding your booking.\r\n• To improve our services and customer experience.\r\n\r\n3. DATA SHARING\r\n• We do NOT sell your personal information to third parties.\r\n• Information may be shared with tour operators only when necessary.\r\n• We may disclose information if required by law.\r\n\r\n4. DATA SECURITY\r\n• We take reasonable measures to protect your data.\r\n• Access is limited to authorized staff only.\r\n• However, no system is 100% secure — use at your own risk.\r\n\r\n5. YOUR RIGHTS\r\n• You may request a copy of your data.\r\n• You may request corrections to your information.\r\n• You may request deletion of your account (subject to legal requirements).\r\n\r\n6. COOKIES\r\n• We use session cookies to keep you logged in.\r\n• No third-party tracking cookies are used.\r\n\r\n7. CHILDREN\'S PRIVACY\r\n• Our services are not intended for children under 18 without parental consent.\r\n\r\n8. CONTACT US\r\n• For privacy concerns, please contact us using the details in the footer.\r\n\r\nBy using our services, you agree to this privacy policy.', '2026-09-16 02:15:33');

-- --------------------------------------------------------

--
-- Table structure for table `bak_2026_10_tour_bookings`
--

CREATE TABLE `bak_2026_10_tour_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `tour_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `booking_date` date NOT NULL,
  `number_of_guests` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `special_requests` text DEFAULT NULL,
  `payment_status` enum('pending','paid','cancelled') DEFAULT 'pending',
  `booking_status` enum('confirmed','cancelled','completed') DEFAULT 'confirmed',
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_notes` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `feedback_is_anonymous` tinyint(1) DEFAULT 0,
  `guest_name` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `gcash_reference` varchar(30) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `package_reference` varchar(50) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bak_2026_10_tour_bookings`
--

INSERT INTO `bak_2026_10_tour_bookings` (`id`, `guest_id`, `tour_id`, `reference_number`, `booking_date`, `number_of_guests`, `total_amount`, `special_requests`, `payment_status`, `booking_status`, `payment_proof`, `proof_uploaded_at`, `paid_at`, `reject_reason`, `reject_notes`, `rejected_by`, `rejected_at`, `created_at`, `feedback_is_anonymous`, `guest_name`, `contact_number`, `preferred_time`, `gcash_reference`, `cancelled_at`, `cancellation_reason`, `package_reference`, `package_id`, `completed_at`) VALUES
(1, 4, 5, 'TOUR-20260910-1001', '2026-09-25', 5, 3000.00, '', 'pending', 'completed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-10 06:14:49', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 4, 5, 'TOUR-20260910-8996', '2026-09-25', 5, 3000.00, '', 'paid', 'completed', NULL, NULL, '2026-09-10 08:54:59', NULL, NULL, NULL, NULL, '2026-09-10 06:14:57', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 4, 5, 'TOUR-20260910-8539', '2026-09-18', 8, 3000.00, 'ahhhhhhhhhh', 'pending', 'completed', '1789055448_TOUR-20260910-8539.png', '2026-09-10 10:50:48', NULL, NULL, NULL, NULL, NULL, '2026-09-10 15:47:27', 0, 'john paul navarro', '09505241711', '07:00:00', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 4, 6, 'TOUR-20260911-5949', '2026-09-26', 16, 4000.00, 'jason\r\nfaye\r\ngian\r\nandrew\r\njhoncel\r\naugust\r\ngids\r\nrainier\r\nrivi\r\nangel\r\nruss\r\njolina\r\nteddy\r\ntom\r\ncj\r\ncedie\r\ndave\r\ncy', 'paid', 'completed', '1789138132_TOUR-20260911-5949.png', '2026-09-11 09:48:52', '2026-09-11 09:57:25', NULL, NULL, NULL, NULL, '2026-09-11 14:48:13', 0, 'John Paul Onia Navarro', '09505241711', '08:00:00', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 4, 7, 'TOUR-20260916-7043', '2026-09-24', 10, 2880.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'pending', 'completed', '1789535461_TOUR-20260916-7043.png', '2026-09-16 13:11:01', NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:10:22', 0, 'John Paul Onia Navarro', '09505241711', '07:00:00', '0045077587865', NULL, NULL, NULL, NULL, NULL),
(6, 4, 6, 'TOUR-20260916-1549', '2026-09-22', 10, 4000.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'paid', 'completed', '1789535837_TOUR-20260916-1549.png', '2026-09-16 13:17:17', '2026-09-16 13:21:07', NULL, NULL, NULL, NULL, '2026-09-16 05:15:49', 0, 'john paul navarro', '09505241711', '07:00:00', '0045077587865', NULL, NULL, NULL, NULL, NULL),
(7, 4, 6, 'TOUR-20260916-4897', '2026-09-17', 10, 4000.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'pending', 'completed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:31:09', 0, 'trimuru tempest', '09505241711', '07:00:00', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 4, 6, 'TOUR-20260916-3670', '2026-09-23', 10, 4000.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'pending', 'completed', '1789613859_TOUR-20260916-3670.png', '2026-09-17 10:57:39', NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:35:40', 0, 'trimuru tempest', '09505241711', '08:00:00', '0045077587865', NULL, NULL, NULL, NULL, NULL),
(9, 5, 6, 'TOUR-20260922-2559', '2026-09-25', 5, 4000.00, 'bakit', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22 08:07:59', 0, 'John Paul Onia Navarro', '09123456678', '07:00:00', NULL, '2026-09-26 22:13:55', NULL, NULL, NULL, NULL),
(10, 5, 7, 'TOUR-20260922-9010', '2026-09-25', 2, 2880.00, 'wal;a', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22 08:08:56', 0, 'John Paul Onia Navarro', '09123456678', '08:00:00', NULL, '2026-09-26 22:13:49', NULL, NULL, NULL, NULL),
(11, 5, 6, 'TOUR-20260924-9652', '2026-10-15', 5, 4000.00, 'zsdfd', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-24 10:45:27', 0, 'trimuru tempest', '+63123456789', '06:00:00', NULL, '2026-09-24 20:22:24', NULL, NULL, NULL, NULL),
(12, 5, 6, 'TOUR-20260924-4407', '2026-10-16', 6, 4000.00, 'dfzfd', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-24 10:58:59', 0, 'trimuru tempest', '+639123456678', '06:00:00', NULL, '2026-09-26 22:13:45', NULL, NULL, NULL, NULL),
(14, 5, 5, 'PKG-20260926-9C97-T', '2026-10-16', 10, 3000.00, 'safe ride', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 11:54:33', 0, 'Rinn O Yoshida', '+639505241711', '08:00:00', NULL, '2026-09-26 22:13:40', NULL, NULL, 1, NULL),
(15, 5, 6, 'TOUR-20260926-6671', '2026-10-21', 5, 4000.00, 'sxfthdty', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:04:25', 0, 'Rinn O Yoshida', '+639505241711', '09:00:00', NULL, '2026-09-26 22:13:36', NULL, NULL, NULL, NULL),
(16, 5, 5, 'PKG-20260926-EB35-T', '2026-09-30', 4, 3000.00, 'asdasda', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:47:07', 0, 'Rinn O Yoshida', '+639505241711', '07:00:00', NULL, '2026-09-27 08:28:49', NULL, NULL, 21, NULL),
(17, 5, 5, 'PKG-20260927-741F-T', '2026-09-30', 2, 3000.00, '', 'pending', 'completed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 01:18:46', 0, 'Rinn O Yoshida', '+639505241711', '09:00:00', NULL, NULL, NULL, NULL, 24, NULL),
(18, 5, 5, 'PKG-20260928-F864-T', '2026-10-28', 8, 3000.00, 'wala', 'paid', '', NULL, NULL, '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, '2026-09-28 21:47:13', 0, 'Rinn yoshida', '+639505241711', '08:00:00', NULL, NULL, NULL, NULL, 26, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `blocked_dates`
--

CREATE TABLE `blocked_dates` (
  `id` int(11) NOT NULL,
  `item_type` enum('house','tour','food') NOT NULL,
  `item_id` int(11) NOT NULL,
  `block_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `block_type` enum('walk_in','maintenance','special_occasion','owner_use','other') DEFAULT 'walk_in',
  `blocked_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `blocked_dates`
--

INSERT INTO `blocked_dates` (`id`, `item_type`, `item_id`, `block_date`, `reason`, `block_type`, `blocked_by`, `created_at`) VALUES
(1, 'house', 4, '2026-10-14', NULL, 'walk_in', 1, '2026-09-29 06:21:32'),
(2, 'house', 4, '2026-10-15', NULL, 'walk_in', 1, '2026-09-29 06:21:32'),
(3, 'house', 4, '2026-10-16', NULL, 'walk_in', 1, '2026-09-29 06:21:32'),
(4, 'house', 5, '2026-10-22', 'Auto-blocked from booking #HS-20260923-2571', 'walk_in', NULL, '2026-10-07 12:05:35'),
(5, 'house', 6, '2026-10-29', 'Auto-blocked from booking #PKG-20260927-4CAA-H', 'walk_in', NULL, '2026-10-07 12:05:35'),
(6, 'house', 4, '2026-10-29', 'Auto-blocked from booking #PKG-20260928-F864-H', 'walk_in', NULL, '2026-10-07 12:05:35'),
(7, 'house', 4, '2026-10-30', 'Auto-blocked from booking #PKG-20260928-F864-H', 'walk_in', NULL, '2026-10-07 12:05:35');

-- --------------------------------------------------------

--
-- Table structure for table `booking_payments`
--

CREATE TABLE `booking_payments` (
  `id` int(11) NOT NULL,
  `booking_type` enum('house','tour','food','package') NOT NULL,
  `booking_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `payment_type` enum('reservation_fee','balance') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(30) NOT NULL DEFAULT 'gcash',
  `gcash_reference` varchar(30) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `source` enum('app','migration') NOT NULL DEFAULT 'app',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `booking_payments`
--

INSERT INTO `booking_payments` (`id`, `booking_type`, `booking_id`, `reference_number`, `payment_type`, `amount`, `payment_method`, `gcash_reference`, `received_at`, `recorded_by`, `source`, `notes`, `created_at`) VALUES
(1, 'house', 16, 'HS-20260816-2228', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-08-16 15:49:41', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(2, 'house', 17, 'HS-20260831-7897', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-08-31 10:02:22', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(3, 'house', 18, 'HS-20260903-7958', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-02 19:24:30', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(4, 'house', 24, 'HS-20260908-2990', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-27 14:14:36', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(5, 'house', 25, 'HS-20260908-1012', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-08 03:33:21', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(6, 'house', 27, 'HS-20260911-4085', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-11 09:56:44', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(7, 'house', 29, 'HS-20260914-3630', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-14 13:59:56', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(8, 'house', 32, 'HS-20260915-9520', 'reservation_fee', 1000.00, 'unknown', NULL, NULL, NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(9, 'house', 33, 'HS-20260916-6124', 'reservation_fee', 1000.00, 'gcash', '0045077587865', '2026-09-16 09:25:30', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(10, 'house', 36, 'HS-20260923-2571', 'reservation_fee', 1000.00, 'gcash', '0045077587865', '2026-09-24 10:40:19', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(11, 'tour', 2, 'TOUR-20260910-8996', 'reservation_fee', 1000.00, 'unknown', NULL, '2026-09-10 08:54:59', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(12, 'tour', 4, 'TOUR-20260911-5949', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-11 09:57:25', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(13, 'tour', 6, 'TOUR-20260916-1549', 'reservation_fee', 1000.00, 'gcash', '0045077587865', '2026-09-16 13:21:07', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(14, 'food', 2, 'FOOD-20260911-8206', 'reservation_fee', 1000.00, 'gcash', NULL, '2026-09-11 09:58:36', NULL, 'migration', 'Historical confirmed payment treated as reservation fee', '2026-10-07 04:05:35'),
(15, 'package', 26, 'PKG-20260928-F864', 'reservation_fee', 1000.00, 'gcash', '23423324234234234234', '2026-09-29 05:59:43', NULL, 'migration', 'Historical confirmed package payment treated as one reservation fee', '2026-10-07 04:05:35');

-- --------------------------------------------------------

--
-- Table structure for table `food_bookings`
--

CREATE TABLE `food_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `food_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `reservation_reference` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `size_variant` varchar(100) DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `number_of_persons` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `reservation_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `special_requests` text DEFAULT NULL,
  `booking_date` date NOT NULL,
  `booking_status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_status` enum('pending','reservation_paid','paid','cancelled') NOT NULL DEFAULT 'pending',
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `reservation_paid_at` datetime DEFAULT NULL,
  `balance_paid_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_notes` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `feedback_rating` int(11) DEFAULT NULL,
  `feedback_text` text DEFAULT NULL,
  `feedback_date` datetime DEFAULT NULL,
  `feedback_is_anonymous` tinyint(4) DEFAULT 0,
  `gcash_reference` varchar(30) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `fulfillment_method` varchar(20) DEFAULT 'pickup',
  `delivery_address` text DEFAULT NULL,
  `package_reference` varchar(50) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `rebook_count` int(11) NOT NULL DEFAULT 0,
  `rebooked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `food_bookings`
--

INSERT INTO `food_bookings` (`id`, `guest_id`, `guest_name`, `food_id`, `reference_number`, `reservation_reference`, `quantity`, `size_variant`, `preferred_date`, `preferred_time`, `number_of_persons`, `total_amount`, `reservation_fee_amount`, `amount_paid`, `special_requests`, `booking_date`, `booking_status`, `payment_status`, `payment_proof`, `proof_uploaded_at`, `paid_at`, `reservation_paid_at`, `balance_paid_at`, `reject_reason`, `reject_notes`, `rejected_by`, `rejected_at`, `created_at`, `feedback_rating`, `feedback_text`, `feedback_date`, `feedback_is_anonymous`, `gcash_reference`, `cancelled_at`, `cancellation_reason`, `contact_number`, `fulfillment_method`, `delivery_address`, `package_reference`, `package_id`, `completed_at`, `rebook_count`, `rebooked_at`) VALUES
(1, 4, 'John Paul Onia Navarro', 1, 'FOOD-20260910-4498', NULL, 1, 'Small', '2026-09-25', '14:00:00', NULL, 1500.00, 1000.00, 0.00, 'sgdfg', '2026-09-11', 'completed', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-10 17:12:57', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'pickup', NULL, NULL, NULL, '2026-09-25 14:00:00', 0, NULL),
(2, 4, 'John Paul Onia Navarro', 1, 'FOOD-20260911-8206', NULL, 1, 'Small', '2026-09-26', '09:00:00', NULL, 1500.00, 1000.00, 1000.00, 'extra rice', '2026-09-11', 'completed', 'reservation_paid', '1789138248_FOOD-20260911-8206.png', '2026-09-11 09:50:48', '2026-09-11 09:58:36', '2026-09-11 09:58:36', NULL, NULL, NULL, NULL, NULL, '2026-09-11 14:50:00', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'pickup', NULL, NULL, NULL, '2026-09-26 09:00:00', 0, NULL),
(3, 5, 'Rinn O Yoshida', 3, 'FOOD-20260922-1499', NULL, 1, 'Small', '2026-09-24', '07:00:00', NULL, 4000.00, 1000.00, 0.00, 'wala', '2026-09-22', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22 08:08:29', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:47', NULL, NULL, 'pickup', NULL, NULL, NULL, NULL, 0, NULL),
(5, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-9C97-F', NULL, 1, 'Small', '2026-10-15', '08:00:00', NULL, 1500.00, 0.00, 0.00, 'no spicy', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 11:54:33', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:38', NULL, '+639505241711', 'pickup', NULL, NULL, 1, NULL, 0, NULL),
(6, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-BB5E-F', NULL, 1, 'Small', '2026-10-22', '09:00:00', NULL, 1500.00, 0.00, 0.00, 'asdfasdf', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:01:57', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:30', NULL, '+639505241711', 'pickup', NULL, NULL, 2, NULL, 0, NULL),
(7, 5, 'Rinn O Yoshida', 3, 'FOOD-20260926-7147', NULL, 1, 'Small', '2026-10-22', '09:00:00', NULL, 4000.00, 1000.00, 0.00, 'afdsdg', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:04:02', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:25', NULL, '+639505241711', 'pickup', NULL, NULL, NULL, NULL, 0, NULL),
(8, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-5FBC-F', NULL, 1, 'Small', '2026-09-29', '10:00:00', NULL, 1500.00, 0.00, 0.00, 'sdgfsdfgsdfg', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:14:50', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:20', NULL, '+639505241711', 'pickup', NULL, NULL, 3, NULL, 0, NULL),
(9, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-AAD9-F', NULL, 1, 'Small', '2026-10-02', '12:00:00', NULL, 4000.00, 0.00, 0.00, 'zsfdsdf', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:16:06', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:14', NULL, '+639505241711', 'pickup', NULL, NULL, 4, NULL, 0, NULL),
(10, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-E2D4-F', NULL, 1, 'Small', '2026-09-27', '08:00:00', NULL, 1500.00, 0.00, 0.00, 'asdfasdf', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:32:58', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:09', NULL, '+639505241711', 'pickup', NULL, NULL, 5, NULL, 0, NULL),
(11, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-8C83-F', NULL, 1, 'Small', '2026-10-01', '13:00:00', NULL, 4000.00, 0.00, 0.00, 'ghhfghfgh', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:46:54', NULL, NULL, NULL, 0, NULL, '2026-09-26 22:14:00', NULL, '+639505241711', 'pickup', NULL, NULL, 6, NULL, 0, NULL),
(12, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-AB28-F', NULL, 1, 'Small', '2026-09-30', '16:00:00', NULL, 4000.00, 0.00, 0.00, 'fdgdfg', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:19:17', NULL, NULL, NULL, 0, NULL, '2026-09-27 08:29:04', NULL, '+639505241711', 'pickup', NULL, NULL, 19, NULL, 0, NULL),
(13, 5, 'Rinn O Yoshida', 3, 'PKG-20260926-EB35-F', NULL, 1, 'Small', '2026-09-29', '11:00:00', NULL, 4000.00, 0.00, 0.00, '', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:47:07', NULL, NULL, NULL, 0, NULL, '2026-09-27 08:28:59', NULL, '+639505241711', 'pickup', NULL, NULL, 21, NULL, 0, NULL),
(14, 5, 'Rinn O Yoshida', 1, 'PKG-20260926-FB6D-F', NULL, 1, 'Small', '2026-09-30', '08:00:00', NULL, 1500.00, 0.00, 0.00, '', '2026-09-26', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 15:19:56', NULL, NULL, NULL, 0, NULL, '2026-09-27 08:28:54', NULL, '+639505241711', 'pickup', NULL, NULL, 23, NULL, 0, NULL),
(15, 5, 'Rinn O Yoshida', 1, 'PKG-20260927-741F-F', NULL, 1, 'Small', '2026-09-30', '08:00:00', NULL, 1500.00, 0.00, 0.00, 'asdasd', '2026-09-27', 'cancelled', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 01:18:46', NULL, NULL, NULL, 0, NULL, '2026-09-29 05:48:18', NULL, '+639505241711', 'pickup', NULL, NULL, 24, NULL, 0, NULL),
(16, 5, 'Rinn O Yoshida', 1, 'PKG-20260927-4CAA-F', NULL, 1, 'Small', '2026-09-28', '11:00:00', NULL, 1500.00, 0.00, 0.00, 'adsasd', '2026-09-27', 'completed', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 07:48:52', NULL, NULL, NULL, 0, NULL, NULL, NULL, '+639505241711', 'pickup', NULL, NULL, 25, '2026-09-28 11:00:00', 0, NULL),
(17, 5, 'Rinn O Yoshida', 1, 'PKG-20260928-F864-F', NULL, 1, 'Small', '2026-10-21', '09:00:00', NULL, 1500.00, 0.00, 0.00, 'wala', '2026-09-29', 'confirmed', 'reservation_paid', NULL, NULL, '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-28 21:47:13', NULL, NULL, NULL, 0, NULL, NULL, NULL, '+639385934075', 'pickup', NULL, NULL, 26, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `food_items`
--

CREATE TABLE `food_items` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'bilao',
  `image` varchar(255) DEFAULT 'default-food.jpg',
  `is_available` tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `inclusions` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `price_original` decimal(10,2) DEFAULT NULL,
  `size_variations` text DEFAULT NULL,
  `gallery_images` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `food_items`
--

INSERT INTO `food_items` (`id`, `name`, `description`, `price`, `category`, `image`, `is_available`, `is_featured`, `inclusions`, `created_at`, `updated_at`, `price_original`, `size_variations`, `gallery_images`) VALUES
(1, 'BILAO PACKAGES', 'Enjoy our food bilao with a mixed seafood, grilled pork or chicken, and Alaminos best garlic longganisa, served with rice and drinks. 🥓🍣', 1500.00, 'bilao', '1788018838_BILAO_PACKAGES.jfif', 1, 0, 'Grilled Liempo\r\nGrilled Pusit\r\nBoneless Bangus\r\nAlaminos Longganisa\r\nButtered Shrimps\r\nOkra & Talong\r\nFried Sara\r\nRice\r\nSawsawan\r\nSoftdrinks\r\nUtensils', '2026-08-29 10:53:58', NULL, NULL, '[{\"size\":\"Small\",\"pax\":\"2-5 PAX\",\"price\":1500},{\"size\":\"Medium\",\"pax\":\"5-8 PAX\",\"price\":2000},{\"size\":\"Large\",\"pax\":\"8-12 PAX\",\"price\":3000},{\"size\":\"XLarge\",\"pax\":\"12-15 PAX\",\"price\":3500}]', NULL),
(3, 'BOODLE FIGHT', 'Enjoy our boodle fight special with grilled liempo, grilled pusit, boneless bangus, Alaminos longganisa, seafood, fresh vegetables, fruits, rice, and drinks, perfect for sharing with family and friends. 🥓🦐🍚', 4000.00, 'boodle_regular', '1789137446_BOODLE_FIGHT.jfif', 1, 0, 'Grilled Liempo\r\nGrilled Pusit\r\nBoneless Bangus\r\nAlaminos Longanisa\r\nButtered Shrimps\r\nCrabs\r\nTahong\r\nOkra\r\nTalong\r\nSaba & Fruits\r\nRice\r\nSawsawan\r\nSoftdrinks', '2026-09-11 09:37:26', NULL, NULL, '[{\"size\":\"Small\",\"pax\":\"6\",\"price\":4000},{\"size\":\"Medium\",\"pax\":\"10\",\"price\":5500},{\"size\":\"Large\",\"pax\":\"15\",\"price\":7500},{\"size\":\"Special\",\"pax\":\"20\",\"price\":9000}]', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `food_orders`
--

CREATE TABLE `food_orders` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `booking_type` enum('house','tour','activity') NOT NULL,
  `order_type` enum('package','a la carte') NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `special_requests` text DEFAULT NULL,
  `status` enum('pending','confirmed','preparing','served','cancelled') DEFAULT 'pending',
  `order_date` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `food_packages`
--

CREATE TABLE `food_packages` (
  `id` int(11) NOT NULL,
  `package_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `guests`
--

CREATE TABLE `guests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `name_suffix` varchar(20) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `id_type` varchar(50) DEFAULT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(200) DEFAULT NULL,
  `emergency_number` varchar(20) DEFAULT NULL,
  `has_asthma` tinyint(1) DEFAULT 0,
  `asthma_severity` varchar(20) DEFAULT NULL,
  `has_inhaler` varchar(10) DEFAULT NULL,
  `asthma_triggers` text DEFAULT NULL,
  `last_attack` date DEFAULT NULL,
  `has_allergies` tinyint(1) DEFAULT 0,
  `allergy_types` text DEFAULT NULL,
  `allergies_details` text DEFAULT NULL,
  `allergy_severity` varchar(20) DEFAULT NULL,
  `has_epipen` varchar(10) DEFAULT NULL,
  `has_medical_condition` tinyint(1) DEFAULT 0,
  `medical_conditions` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `has_dietary` tinyint(1) DEFAULT 0,
  `dietary_types` text DEFAULT NULL,
  `dietary_other` text DEFAULT NULL,
  `has_accessibility` tinyint(1) DEFAULT 0,
  `access_needs` text DEFAULT NULL,
  `accessibility_other` text DEFAULT NULL,
  `emergency_medication` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_photo` varchar(255) DEFAULT NULL,
  `id_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `guests`
--

INSERT INTO `guests` (`id`, `user_id`, `full_name`, `first_name`, `middle_name`, `last_name`, `name_suffix`, `contact_number`, `email`, `address`, `id_type`, `id_number`, `emergency_contact`, `emergency_number`, `has_asthma`, `asthma_severity`, `has_inhaler`, `asthma_triggers`, `last_attack`, `has_allergies`, `allergy_types`, `allergies_details`, `allergy_severity`, `has_epipen`, `has_medical_condition`, `medical_conditions`, `medications`, `blood_type`, `has_dietary`, `dietary_types`, `dietary_other`, `has_accessibility`, `access_needs`, `accessibility_other`, `emergency_medication`, `created_at`, `profile_photo`, `id_photo`) VALUES
(4, 8, 'John Paul Onia Navarro', NULL, NULL, NULL, NULL, '09505241711', 'johnpaulnavarro0105@gmail.com', 'pobalacion alaminos city pang', 'Driver\'s License', '1234-5678-9012', 'John Paul Navarro', '09660211542', 0, '', '', '', '0000-00-00', 0, NULL, '', NULL, NULL, 0, '', '', '', 0, NULL, '', 0, NULL, '', '', '2026-08-06 17:04:17', 'profile_1786040031.jpg', 'id_1786035857.png'),
(5, 9, 'Rinn O Yoshida', NULL, NULL, NULL, NULL, '09505241711', 'kanonshibuya33@gmail.com', 'pobalacion alaminos city pang', 'Driver\'s License', '1234-5678-9012', 'John Paul Navarro', '09660211542', 0, '', '', '', NULL, 0, '[]', '', NULL, NULL, 0, '', '', '', 0, '[]', '', 0, '[]', '', '', '2026-09-15 05:09:50', 'profile_1789448990.png', 'id_1789448990.png'),
(8, 13, 'John paul Navarro', 'John paul', NULL, 'Navarro', NULL, '+639605241711', 'fujiwarachika034@gmail.com', 'pobalacion alaminos city pang', 'Driver\'s License', 'AG123253453452345234', 'John Paul Navarro', '+6309660211542', 0, '', '', '', NULL, 0, '[]', '', NULL, NULL, 0, '', '', '', 0, '[]', '', 0, '[]', '', '', '2026-09-28 00:35:16', 'profile_1790555716.png', 'id_1790555716.jfif');

-- --------------------------------------------------------

--
-- Table structure for table `houses`
--

CREATE TABLE `houses` (
  `id` int(11) NOT NULL,
  `house_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `price_per_night` decimal(10,2) NOT NULL,
  `capacity` int(11) NOT NULL,
  `bedrooms` int(11) DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `status` enum('available','booked','maintenance') DEFAULT 'available',
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `houses`
--

INSERT INTO `houses` (`id`, `house_name`, `description`, `price_per_night`, `capacity`, `bedrooms`, `amenities`, `status`, `image`, `created_at`) VALUES
(4, 'UNIT 1', 'Transient House/Accommodation near Tourism Office and Boat Station of Hundred Islands. 🏡🏝️', 5500.00, 25, 3, '• 3 Rooms good for 20-25PAX.\r\n• Fully Airconditioned rooms.\r\n• With own comfort room.\r\n• With own kitchen and basic kitchen utensils.\r\n• With parking area.\r\n• Exclusive service boat for Hundred Islands Tour.\r\n• 12-14 Islands to visit.\r\n• Entrance Fee, Environmental Fee And Insurance Fee.', 'available', '1786651547_0_House_20260813757.jfif', '2026-08-13 20:05:47'),
(5, 'UNIT 2', 'Transient House/Accommodation near Tourism Office and Boat Station of Hundred Islands. 🏡🏝️', 6000.00, 14, 2, '• 2 Rooms good for 12-14 PAX.\r\n• Fully Airconditioned rooms.\r\n• With own comfort room.\r\n• With own kitchen and basic kitchen utensils.\r\n• With parking area.\r\n• Exclusive service boat for Hundred Islands Tour.\r\n• 12-14 Islands to visit.\r\n• Entrance Fee, Environmental Fee And Insurance Fee.\r\n❌ Separate payment other optional activities.', 'available', '1789123589_UNIT_2.jpg', '2026-09-11 10:46:29'),
(6, 'UNIT 3', 'Transient House/Accommodation near Tourism Office and Boat Station of Hundred Islands. 🏡🏝️', 2000.00, 10, 1, '• Good for 8-10PAX Airconditioned room.\r\n• With own comfort room.\r\n• With own kitchen and basic kitchen utensils.\r\n• With parking area.\r\n• Exclusive service boat for Hundred Islands Tour.\r\n• 12-14 Islands to visit.\r\n• Entrance Fee, Environmental Fee And Insurance Fee.\r\n❌ Separate payment other optional activities.', 'available', '1789123912_UNIT_3.jpg', '2026-09-11 10:51:52');

-- --------------------------------------------------------

--
-- Table structure for table `house_bookings`
--

CREATE TABLE `house_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `house_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_in_time` time DEFAULT '14:00:00',
  `check_out_date` date NOT NULL,
  `check_out_time` time DEFAULT '12:00:00',
  `number_of_guests` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `reservation_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `special_requests` text DEFAULT NULL,
  `payment_status` enum('pending','reservation_paid','paid','cancelled') NOT NULL DEFAULT 'pending',
  `booking_status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `reservation_paid_at` datetime DEFAULT NULL,
  `balance_paid_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_notes` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `feedback_is_anonymous` tinyint(1) DEFAULT 0,
  `rebook_count` int(11) NOT NULL DEFAULT 0,
  `previous_check_in_date` date DEFAULT NULL,
  `previous_check_out_date` date DEFAULT NULL,
  `previous_number_of_guests` int(11) DEFAULT NULL,
  `previous_total_amount` decimal(10,2) DEFAULT NULL,
  `previous_booking_status` varchar(20) DEFAULT NULL,
  `rebook_confirmed_at` datetime DEFAULT NULL,
  `rebooked_at` datetime DEFAULT NULL,
  `original_booking_id` int(11) DEFAULT NULL,
  `booking_date` datetime NOT NULL DEFAULT current_timestamp(),
  `guest_names` text DEFAULT NULL,
  `gcash_reference` varchar(30) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `package_reference` varchar(50) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `house_bookings`
--

INSERT INTO `house_bookings` (`id`, `guest_id`, `house_id`, `reference_number`, `check_in_date`, `check_in_time`, `check_out_date`, `check_out_time`, `number_of_guests`, `total_amount`, `reservation_fee_amount`, `amount_paid`, `special_requests`, `payment_status`, `booking_status`, `completed_at`, `payment_proof`, `proof_uploaded_at`, `paid_at`, `reservation_paid_at`, `balance_paid_at`, `reject_reason`, `reject_notes`, `rejected_by`, `rejected_at`, `created_at`, `feedback_is_anonymous`, `rebook_count`, `previous_check_in_date`, `previous_check_out_date`, `previous_number_of_guests`, `previous_total_amount`, `previous_booking_status`, `rebook_confirmed_at`, `rebooked_at`, `original_booking_id`, `booking_date`, `guest_names`, `gcash_reference`, `cancelled_at`, `cancellation_reason`, `package_reference`, `package_id`) VALUES
(14, 4, 4, 'HS-20260814-4537', '2026-09-15', '14:00:00', '2026-09-17', '12:00:00', 20, 11000.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1786702958_HS-20260814-4537.png', '2026-08-14 05:22:38', '2026-08-14 05:51:06', NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-14 10:22:20', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-14 05:22:20', 'gian\r\nfaye\r\nedwin\r\ngids\r\nedward\r\nandrew\r\nrainier\r\nyoanna\r\njhoncel\r\npatrick\r\npot\r\nsilver\r\nace\r\nraiden\r\nbronya\r\nsonya\r\nmouse\r\nkeyboard\r\nmonitor\r\ncellphone', NULL, NULL, NULL, NULL, NULL),
(15, 4, 4, 'HS-20260816-4212', '2026-09-16', '14:00:00', '2026-09-18', '12:00:00', 15, 11000.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1786911581_HS-20260816-4212.png', '2026-08-16 15:19:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-16 20:19:22', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-16 15:19:22', 'giana gian\r\nentoy entoy\r\nfaye faye\r\nyoanna yoanna\r\njhoncel jhoncel\r\npat trick\r\nlen odi\r\ncell phone\r\ntedi bear\r\nda mit\r\nmo use\r\nsa patos\r\nka ma\r\nil aw\r\nun an\r\nper fume', NULL, NULL, NULL, NULL, NULL),
(16, 4, 4, 'HS-20260816-2228', '2026-09-16', '14:00:00', '2026-09-18', '12:00:00', 10, 11000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'cancelled', NULL, '1786912289_HS-20260816-2228.png', '2026-08-16 15:31:29', '2026-08-16 15:49:41', '2026-08-16 15:49:41', NULL, NULL, NULL, NULL, NULL, '2026-08-16 20:31:15', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-16 15:31:15', 'gia na\r\nfa ye\r\nen toy\r\nyoa nna\r\njhon cel\r\npat rick\r\nle nodi\r\ntedi bear\r\nu nan\r\ncell phon\r\nda mit', NULL, '2026-09-27 14:03:11', 'Booking dates no longer available', NULL, NULL),
(17, 4, 4, 'HS-20260831-7897', '2026-09-28', '14:00:00', '2026-09-30', '12:00:00', 12, 11000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'cancelled', NULL, '1788186425_HS-20260831-7897.png', '2026-08-31 09:27:06', '2026-08-31 10:02:22', '2026-08-31 10:02:22', NULL, NULL, NULL, NULL, NULL, '2026-08-31 14:26:19', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-31 09:26:19', 'try1\r\ntry2\r\ntry3\r\ntry4\r\ntry5\r\ntry6\r\ntry7\r\ntry8\r\ntry9\r\ntry10\r\ntry11\r\ntry12', NULL, '2026-09-27 14:15:11', 'Guest requested cancellation', NULL, NULL),
(18, 4, 4, 'HS-20260903-7958', '2026-11-10', '14:00:00', '2026-11-12', '12:00:00', 8, 11000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'cancelled', NULL, '1788395025_HS-20260903-7958.png', '2026-09-02 19:23:45', '2026-09-02 19:24:30', '2026-09-02 19:24:30', NULL, NULL, NULL, NULL, NULL, '2026-09-03 00:22:35', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-02 19:22:35', 'user1\r\nuser2\r\nuser3\r\nuser4\r\nuser5\r\nuser6\r\nuser7\r\nuser8\r\nuser9\r\nuser10', NULL, '2026-09-27 14:12:35', 'Guest requested cancellation', NULL, NULL),
(19, 4, 4, 'HS-20260907-4031', '2026-10-13', '14:00:00', '2026-10-16', '12:00:00', 10, 16500.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1788790909_HS-20260907-4031.png', '2026-09-07 09:21:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 14:20:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 09:20:54', 'user1\r\nuser2\r\nuser3\r\nuser4\r\nuser5\r\nuser6\r\nuser7\r\nuser8\r\nuser9\r\nuser10', NULL, NULL, NULL, NULL, NULL),
(20, 4, 4, 'HS-20260907-1136', '2026-10-12', '14:00:00', '2026-10-15', '12:00:00', 10, 16500.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1788793238_HS-20260907-1136.png', '2026-09-07 10:00:38', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 14:58:22', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 09:58:22', '1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8\r\n9\r\n10', NULL, NULL, NULL, NULL, NULL),
(21, 4, 4, 'HS-20260907-4480', '2026-10-20', '14:00:00', '2026-10-22', '12:00:00', 11, 11000.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1788794258_HS-20260907-4480.png', '2026-09-07 10:17:38', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 15:16:39', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 10:16:39', '1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8\r\n9\r\n10\r\n11', NULL, NULL, NULL, NULL, NULL),
(22, 4, 4, 'HS-20260907-5077', '2026-09-23', '14:00:00', '2026-09-25', '12:00:00', 5, 11000.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1788798004_HS-20260907-5077.png', '2026-09-07 11:20:04', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 15:23:58', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 10:23:58', '1\r\n2\r\n3\r\n4\r\n5', NULL, NULL, NULL, NULL, NULL),
(23, 4, 4, 'HS-20260907-9985', '2026-09-19', '14:00:00', '2026-09-20', '12:00:00', 3, 5500.00, 1000.00, 0.00, NULL, 'pending', 'cancelled', NULL, '1788798217_HS-20260907-9985.png', '2026-09-07 11:23:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 16:23:09', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-07 11:23:09', '1\r\n2\r\n3', NULL, '2026-09-27 14:14:03', 'Guest requested cancellation', NULL, NULL),
(24, 4, 4, 'HS-20260908-2990', '2026-09-24', '14:00:00', '2026-09-26', '12:00:00', 6, 11000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'cancelled', NULL, '1788853728_HS-20260908-2990.png', '2026-09-08 02:48:48', '2026-09-27 14:14:36', '2026-09-27 14:14:36', NULL, NULL, NULL, NULL, NULL, '2026-09-08 05:58:41', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 00:58:41', '1\r\n2\r\n3\r\n4\r\n5\r\n6', NULL, '2026-09-27 14:15:24', 'Guest requested cancellation', NULL, NULL),
(25, 4, 4, 'HS-20260908-1012', '2026-09-22', '14:00:00', '2026-09-23', '12:00:00', 6, 5500.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'completed', '2026-09-23 12:00:00', '1788853939_HS-20260908-1012.png', '2026-09-08 02:52:19', '2026-09-08 03:33:21', '2026-09-08 03:33:21', NULL, NULL, NULL, NULL, NULL, '2026-09-08 07:51:56', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 02:51:56', '1\r\n2\r\n3\r\n4\r\n5\r\n6', NULL, NULL, NULL, NULL, NULL),
(26, 4, 4, 'HS-20260908-5828', '2026-10-13', '14:00:00', '2026-10-15', '12:00:00', 8, 11000.00, 1000.00, 0.00, NULL, 'cancelled', 'cancelled', NULL, '1788856794_HS-20260908-5828.png', '2026-09-08 03:39:54', '2026-09-08 03:44:20', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 08:39:21', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-08 03:39:21', '1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8', NULL, NULL, NULL, NULL, NULL),
(27, 4, 6, 'HS-20260911-4085', '2026-09-22', '14:00:00', '2026-09-24', '12:00:00', 8, 4000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'completed', '2026-09-24 12:00:00', '1789137891_HS-20260911-4085.png', '2026-09-11 09:44:51', '2026-09-11 09:56:44', '2026-09-11 09:56:44', NULL, NULL, NULL, NULL, NULL, '2026-09-11 14:43:51', 0, 2, NULL, NULL, NULL, NULL, NULL, '2026-09-14 22:39:22', '2026-09-14 07:59:36', NULL, '2026-09-11 09:43:51', 'gian\r\nfaye\r\nentoy\r\nyoanna\r\njhoncel\r\npatrick \r\nandrew\r\naugust', NULL, NULL, NULL, NULL, NULL),
(28, 4, 6, 'RE-20260911-2535', '2026-10-22', '14:00:00', '2026-10-24', '12:00:00', 1, 4000.00, 0.00, 0.00, NULL, 'paid', 'pending', NULL, '1789139286_RE-20260911-2535.png', '2026-09-11 10:08:06', '2026-09-11 10:09:04', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-11 15:07:47', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 27, '2026-09-11 10:07:47', NULL, NULL, NULL, NULL, NULL, NULL),
(29, 4, 5, 'HS-20260914-3630', '2026-09-23', '14:00:00', '2026-09-25', '12:00:00', 10, 12000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'completed', '2026-09-25 12:00:00', '1789411226_HS-20260914-3630.png', '2026-09-14 13:40:26', '2026-09-14 13:59:56', '2026-09-14 13:59:56', NULL, NULL, NULL, NULL, NULL, '2026-09-14 18:39:57', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-14 13:39:57', 'gian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', NULL, NULL, NULL, NULL, NULL),
(32, 5, 5, 'HS-20260915-9520', '2026-09-29', '14:00:00', '2026-09-30', '12:00:00', 10, 6000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-14 01:51:47', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-15 12:12:52', 'gian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', NULL, '2026-09-29 23:01:17', 'Guest requested cancellation', NULL, NULL),
(33, 5, 6, 'HS-20260916-6124', '2026-09-25', '14:00:00', '2026-09-26', '12:00:00', 10, 2000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'completed', '2026-09-26 12:00:00', '1789521247_HS-20260916-6124.png', '2026-09-16 09:14:07', '2026-09-16 09:25:30', '2026-09-16 09:25:30', NULL, NULL, NULL, NULL, NULL, '2026-09-16 01:00:47', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-16 09:00:47', 'gian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', '0045077587865', NULL, NULL, NULL, NULL),
(35, 5, 5, 'HS-20260923-8791', '2026-10-29', '14:00:00', '2026-10-31', '12:00:00', 5, 12000.00, 1000.00, 0.00, NULL, 'pending', 'cancelled', NULL, '1790671505_HS-20260923-8791.png', '2026-09-29 16:45:05', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-23 06:25:47', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-23 14:25:47', 'gian\nentoy\ndaye\nfaye\ngust', '23423324234234234234', '2026-09-29 14:27:54', 'Guest requested cancellation', NULL, NULL),
(36, 5, 5, 'HS-20260923-2571', '2026-10-22', '14:00:00', '2026-10-23', '12:00:00', 2, 6000.00, 1000.00, 1000.00, NULL, 'reservation_paid', 'confirmed', NULL, '1790215789_HS-20260923-2571.png', '2026-09-24 10:09:49', '2026-09-24 10:40:19', '2026-09-24 10:40:19', NULL, NULL, NULL, NULL, NULL, '2026-09-23 06:26:28', 0, 1, '2026-10-23', '2026-10-24', 2, 6000.00, NULL, '2026-09-24 13:51:18', '2026-09-24 13:51:06', NULL, '2026-09-23 14:26:28', 'john\npaul', '0045077587865', NULL, NULL, NULL, NULL),
(38, 5, 6, 'PKG-20260926-9C97-H', '2026-10-15', '14:00:00', '2026-10-17', '12:00:00', 10, 4000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 11:54:33', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 19:54:33', 'kanon\nrinn\nchika\nfreiren\nshibuya\nkyoto\nmaro\nshinigami\ntensie\nshinra', NULL, '2026-09-26 20:48:34', NULL, NULL, 1),
(39, 5, 4, 'PKG-20260926-BB5E-H', '2026-10-22', '14:00:00', '2026-10-23', '12:00:00', 4, 5500.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:01:57', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:01:57', 'sdfsdf\nsdfsdf\nasdfsdf\nsdfsdfa', NULL, '2026-09-26 20:48:31', NULL, NULL, 2),
(40, 5, 6, 'PKG-20260926-5FBC-H', '2026-09-28', '14:00:00', '2026-09-30', '12:00:00', 5, 4000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:14:50', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:14:50', 'sdfsdf\nsdafsdfsdfs\nsdfasdfsd\nasdfsdfasdf\nsdfasdfds', NULL, '2026-09-26 20:48:26', NULL, NULL, 3),
(41, 5, 6, 'PKG-20260926-AAD9-H', '2026-10-01', '14:00:00', '2026-10-02', '12:00:00', 4, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:16:06', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:16:06', 'asdf\nasasdf\nasdfsdf\nsdfasdfas', NULL, '2026-09-26 20:48:23', NULL, NULL, 4),
(42, 5, 5, 'PKG-20260926-E2D4-H', '2026-10-15', '14:00:00', '2026-10-17', '12:00:00', 5, 12000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:32:58', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:32:58', 'sdfs\nasdfasdf\nasdfsdf\nadsfasdf\nsdfasdf', NULL, '2026-09-26 20:48:20', NULL, NULL, 5),
(43, 5, 6, 'PKG-20260926-8C83-H', '2026-10-03', '14:00:00', '2026-10-04', '12:00:00', 4, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:46:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:46:54', 'sdfsfdsfg\nsdfgdfgdf\nssdfsdfsd\nsdfsdfsdf', NULL, '2026-09-26 20:47:52', NULL, NULL, 6),
(44, 5, 6, 'PKG-20260926-F8E2-H', '2026-09-27', '14:00:00', '2026-09-28', '12:00:00', 2, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:49:05', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:49:05', 'sdfgsdfg\nsdfgdsfg', NULL, '2026-09-26 22:13:31', NULL, NULL, 7),
(45, 5, 6, 'PKG-20260926-7F11-H', '2026-09-29', '14:00:00', '2026-09-30', '12:00:00', 2, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:49:38', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:49:38', 'xdfg\ndfgdf', NULL, '2026-09-26 22:13:27', NULL, NULL, 8),
(46, 5, 6, 'PKG-20260926-02DD-H', '2026-10-29', '14:00:00', '2026-10-30', '12:00:00', 2, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:50:03', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:50:03', 'ette\nteer', NULL, '2026-09-26 22:13:24', NULL, NULL, 9),
(47, 5, 5, 'PKG-20260926-16E3-H', '2026-09-27', '14:00:00', '2026-09-28', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:50:31', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:50:31', 'yrtyrty\nrtyrty', NULL, '2026-09-26 22:13:21', NULL, NULL, 10),
(48, 5, 6, 'PKG-20260926-0960-H', '2026-10-01', '14:00:00', '2026-10-02', '12:00:00', 2, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:50:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:50:54', 'dd\ndfgdfg', NULL, '2026-09-26 21:22:08', NULL, NULL, 11),
(49, 5, 6, 'PKG-20260926-6CA2-H', '2026-10-03', '14:00:00', '2026-10-04', '12:00:00', 2, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:51:24', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:51:24', 'fgdfg\ndfgdfg', NULL, '2026-09-26 21:22:05', NULL, NULL, 12),
(50, 5, 5, 'PKG-20260926-F6B8-H', '2026-10-01', '14:00:00', '2026-10-02', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 13:05:07', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:05:07', 'zsdfsd\nszdfsdf', NULL, '2026-09-26 21:22:02', NULL, NULL, 13),
(51, 5, 6, 'PKG-20260926-8B41-H', '2026-10-08', '14:00:00', '2026-10-09', '12:00:00', 2, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 13:05:54', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:05:54', 'sdfsdf\nsdfsdf', NULL, '2026-09-26 21:07:05', NULL, NULL, 16),
(52, 5, 5, 'PKG-20260926-F39F-H', '2026-10-09', '14:00:00', '2026-10-10', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 13:18:37', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:18:37', 'fsdfsdf\nsdfsdf', NULL, '2026-09-26 21:21:58', NULL, NULL, 17),
(53, 5, 5, 'PKG-20260926-5633-H', '2026-09-26', '14:00:00', '2026-09-27', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:17:48', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 22:17:48', 'sfdsf\nsdfsdf', NULL, '2026-09-27 08:28:39', NULL, NULL, 18),
(54, 5, 5, 'PKG-20260926-AB28-H', '2026-10-15', '14:00:00', '2026-10-16', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:19:17', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 22:19:17', 'sdfds\nsdfsdf', NULL, '2026-09-27 08:28:35', NULL, NULL, 19),
(55, 5, 6, 'PKG-20260926-EB35-H', '2026-09-28', '14:00:00', '2026-09-29', '12:00:00', 4, 2000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:47:07', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 22:47:07', 'dasdad\nasdasdas\nasdasda\nasdasdas', NULL, '2026-09-27 08:28:32', NULL, NULL, 21),
(56, 5, 5, 'PKG-20260926-FB6D-H', '2026-10-02', '14:00:00', '2026-10-03', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 15:19:56', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 23:19:56', 'ggffg\nfgxhf', NULL, '2026-09-27 08:28:28', NULL, NULL, 23),
(57, 5, 5, 'PKG-20260927-741F-H', '2026-09-27', '14:00:00', '2026-09-28', '12:00:00', 2, 6000.00, 0.00, 0.00, NULL, 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 01:18:46', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 09:18:46', 'asdasd\nasdasd', NULL, '2026-09-29 05:48:18', NULL, NULL, 24),
(58, 5, 6, 'PKG-20260927-4CAA-H', '2026-10-29', '14:00:00', '2026-10-30', '12:00:00', 3, 2000.00, 0.00, 0.00, NULL, 'pending', 'confirmed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 07:48:52', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 15:48:52', 'sdfdsf\nsdfsdf\nsdfsdf', NULL, NULL, NULL, NULL, 25),
(59, 5, 4, 'PKG-20260928-F864-H', '2026-10-29', '07:00:00', '2026-10-31', '18:00:00', 5, 11000.00, 0.00, 0.00, NULL, 'reservation_paid', 'confirmed', NULL, NULL, NULL, '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-28 21:47:13', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-29 05:47:13', 'asdasdas\nasdasd\nasdasd\nasdasd\nasdasd', NULL, NULL, NULL, NULL, 26),
(60, 5, 4, 'HS-20261002-2181', '2026-10-28', '14:00:00', '2026-10-30', '12:00:00', 1, 11000.00, 1000.00, 0.00, NULL, 'pending', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 04:57:42', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 12:57:42', 'sadasdas', NULL, NULL, NULL, NULL, NULL),
(61, 5, 4, 'HS-20261006-1908', '2026-10-22', '05:00:00', '2026-10-24', '12:00:00', 1, 11000.00, 1000.00, 0.00, NULL, 'pending', 'pending', NULL, '1791303272_HS-20261006-1908.png', '2026-10-06 12:14:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-06 16:13:52', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-06 12:13:52', 'ahahah', '23423324234234234234', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `house_gallery`
--

CREATE TABLE `house_gallery` (
  `id` int(11) NOT NULL,
  `house_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `is_main` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `house_gallery`
--

INSERT INTO `house_gallery` (`id`, `house_id`, `image`, `caption`, `is_main`, `sort_order`, `created_at`) VALUES
(5, 4, '1786651547_0_House_20260813757.jfif', NULL, 1, 0, '2026-08-13 20:05:47'),
(6, 4, '1786651547_1_House_20260813757.jfif', NULL, 0, 1, '2026-08-13 20:05:47'),
(7, 4, '1786651547_2_House_20260813757.jfif', NULL, 0, 2, '2026-08-13 20:05:47'),
(8, 4, '1786651547_3_House_20260813757.jfif', NULL, 0, 3, '2026-08-13 20:05:47'),
(10, 5, '1789123589_0_UNIT_2.jpg', NULL, 1, 0, '2026-09-11 10:46:29'),
(11, 5, '1789123589_1_UNIT_2.jpg', NULL, 0, 1, '2026-09-11 10:46:30'),
(12, 5, '1789123590_2_UNIT_2.jpg', NULL, 0, 2, '2026-09-11 10:46:30'),
(13, 5, '1789123590_3_UNIT_2.jpg', NULL, 0, 3, '2026-09-11 10:46:30'),
(14, 5, '1789123590_4_UNIT_2.jpg', NULL, 0, 4, '2026-09-11 10:46:30'),
(15, 5, '1789123590_5_UNIT_2.jpg', NULL, 0, 5, '2026-09-11 10:46:30'),
(16, 6, '1789123912_0_UNIT_3.jpg', NULL, 1, 0, '2026-09-11 10:51:52'),
(17, 6, '1789123912_1_UNIT_3.jpg', NULL, 0, 1, '2026-09-11 10:51:52'),
(18, 6, '1789123912_2_UNIT_3.jpg', NULL, 0, 2, '2026-09-11 10:51:52'),
(19, 6, '1789123912_3_UNIT_3.jpg', NULL, 0, 3, '2026-09-11 10:51:52'),
(20, 6, '1789123912_4_UNIT_3.jpg', NULL, 0, 4, '2026-09-11 10:51:52');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `lockout_level` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `identifier`, `ip_address`, `attempt_count`, `lockout_level`, `locked_until`, `last_attempt`, `created_at`) VALUES
(17, 'john', '127.0.0.1', 3, 0, NULL, '2026-09-27 20:09:16', '2026-09-27 20:09:02'),
(18, 'rinn', '127.0.0.1', 0, 1, '2026-09-27 14:19:01', '2026-09-27 20:14:01', '2026-09-27 20:13:48'),
(19, 'jjohn', '127.0.0.1', 1, 0, NULL, '2026-09-27 20:14:34', '2026-09-27 20:14:34'),
(25, 'otp-send:11', '127.0.0.1', 1, 0, NULL, '2026-10-01 15:20:32', '2026-10-01 11:06:24'),
(26, 'otp-send:1', '127.0.0.1', 2, 0, NULL, '2026-10-01 15:20:10', '2026-10-01 15:19:57'),
(27, 'otp-send:9', '127.0.0.1', 1, 0, NULL, '2026-10-01 15:20:50', '2026-10-01 15:20:50'),
(30, '49.151.133.253', '49.151.133.253', 0, 0, NULL, '2026-10-06 09:43:57', '2026-10-06 09:43:40');

-- --------------------------------------------------------

--
-- Table structure for table `migration_2026_10_audit`
--

CREATE TABLE `migration_2026_10_audit` (
  `id` int(11) NOT NULL,
  `step` varchar(40) NOT NULL,
  `table_name` varchar(40) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `reference_number` varchar(60) DEFAULT NULL,
  `field_name` varchar(60) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `migration_2026_10_audit`
--

INSERT INTO `migration_2026_10_audit` (`id`, `step`, `table_name`, `record_id`, `reference_number`, `field_name`, `old_value`, `new_value`, `reason`, `created_at`) VALUES
(1, '3.1', 'house_bookings', 28, 'RE-20260911-2535', 'booking_status', '', 'pending', 'Empty status stored because enum lacked pending', '2026-10-07 04:05:35'),
(2, '3.1', 'house_bookings', 59, 'PKG-20260928-F864-H', 'booking_status', '', 'pending', 'Empty status stored because enum lacked pending', '2026-10-07 04:05:35'),
(3, '3.1', 'house_bookings', 60, 'HS-20261002-2181', 'booking_status', '', 'pending', 'Empty status stored because enum lacked pending', '2026-10-07 04:05:35'),
(4, '3.1', 'house_bookings', 61, 'HS-20261006-1908', 'booking_status', '', 'pending', 'Empty status stored because enum lacked pending', '2026-10-07 04:05:35'),
(5, '3.1', 'tour_bookings', 18, 'PKG-20260928-F864-T', 'booking_status', '', 'pending', 'Empty status stored because enum lacked pending', '2026-10-07 04:05:35'),
(8, '3.2', 'food_bookings', 1, 'FOOD-20260910-4498', 'booking_date', '0000-00-00', '2026-09-11', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(9, '3.2', 'food_bookings', 2, 'FOOD-20260911-8206', 'booking_date', '0000-00-00', '2026-09-11', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(10, '3.2', 'food_bookings', 3, 'FOOD-20260922-1499', 'booking_date', '0000-00-00', '2026-09-22', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(11, '3.2', 'food_bookings', 5, 'PKG-20260926-9C97-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(12, '3.2', 'food_bookings', 6, 'PKG-20260926-BB5E-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(13, '3.2', 'food_bookings', 7, 'FOOD-20260926-7147', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(14, '3.2', 'food_bookings', 8, 'PKG-20260926-5FBC-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(15, '3.2', 'food_bookings', 9, 'PKG-20260926-AAD9-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(16, '3.2', 'food_bookings', 10, 'PKG-20260926-E2D4-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(17, '3.2', 'food_bookings', 11, 'PKG-20260926-8C83-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(18, '3.2', 'food_bookings', 12, 'PKG-20260926-AB28-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(19, '3.2', 'food_bookings', 13, 'PKG-20260926-EB35-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(20, '3.2', 'food_bookings', 14, 'PKG-20260926-FB6D-F', 'booking_date', '0000-00-00', '2026-09-26', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(21, '3.2', 'food_bookings', 15, 'PKG-20260927-741F-F', 'booking_date', '0000-00-00', '2026-09-27', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(22, '3.2', 'food_bookings', 16, 'PKG-20260927-4CAA-F', 'booking_date', '0000-00-00', '2026-09-27', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(23, '3.2', 'food_bookings', 17, 'PKG-20260928-F864-F', 'booking_date', '0000-00-00', '2026-09-29', 'Zero date backfilled from created_at (reservation creation date)', '2026-10-07 04:05:35'),
(39, '3.3', 'food_items', 1, 'BILAO PACKAGES', 'category', '', 'bilao', 'Empty category (old enum rejected bilao/boodle values); mapped from item name', '2026-10-07 04:05:35'),
(40, '3.3', 'food_items', 3, 'BOODLE FIGHT', 'category', '', 'boodle_regular', 'Empty category (old enum rejected bilao/boodle values); mapped from item name', '2026-10-07 04:05:35'),
(42, '3.4', 'house_bookings', 27, 'HS-20260911-4085', 'total_amount', '32000.00', '4000.00', 'Rebook bug: 2000.00/night x 2 night(s) x 8 guests. Corrected to nightly rate x nights.', '2026-10-07 04:05:35'),
(43, '3.4', 'house_bookings', 29, 'HS-20260914-3630', 'total_amount', '120000.00', '12000.00', 'Rebook bug: 6000.00/night x 2 night(s) x 10 guests. Corrected to nightly rate x nights.', '2026-10-07 04:05:35'),
(44, '3.4', 'house_bookings', 36, 'HS-20260923-2571', 'total_amount', '12000.00', '6000.00', 'Rebook bug: 6000.00/night x 1 night(s) x 2 guests. Corrected to nightly rate x nights.', '2026-10-07 04:05:35'),
(45, '3.6', 'house_bookings', 16, 'HS-20260816-2228', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(46, '3.6', 'house_bookings', 17, 'HS-20260831-7897', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(47, '3.6', 'house_bookings', 18, 'HS-20260903-7958', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(48, '3.6', 'house_bookings', 24, 'HS-20260908-2990', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(49, '3.6', 'house_bookings', 25, 'HS-20260908-1012', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(50, '3.6', 'house_bookings', 27, 'HS-20260911-4085', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(51, '3.6', 'house_bookings', 29, 'HS-20260914-3630', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(52, '3.6', 'house_bookings', 32, 'HS-20260915-9520', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(53, '3.6', 'house_bookings', 33, 'HS-20260916-6124', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(54, '3.6', 'house_bookings', 36, 'HS-20260923-2571', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(55, '3.6', 'tour_bookings', 2, 'TOUR-20260910-8996', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(56, '3.6', 'tour_bookings', 4, 'TOUR-20260911-5949', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(57, '3.6', 'tour_bookings', 6, 'TOUR-20260916-1549', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(58, '3.6', 'food_bookings', 2, 'FOOD-20260911-8206', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical \"paid\" = confirmed reservation payment; remaining balance not assumed paid', '2026-10-07 04:05:35'),
(59, '3.6', 'package_bookings', 26, 'PKG-20260928-F864', 'payment_status/amount_paid', 'paid / 0.00', 'reservation_paid / 1000.00', 'Historical package \"paid\" = one confirmed reservation payment for the whole package', '2026-10-07 04:05:35'),
(60, '3.7', 'house_bookings', 57, 'PKG-20260927-741F-H', 'booking_status', 'completed', 'cancelled', 'Package PKG-20260927-741F is cancelled', '2026-10-07 04:05:35'),
(61, '3.7', 'tour_bookings', 17, 'PKG-20260927-741F-T', 'booking_status', 'completed', 'cancelled', 'Package PKG-20260927-741F is cancelled', '2026-10-07 04:05:35'),
(62, '3.7', 'food_bookings', 15, 'PKG-20260927-741F-F', 'booking_status', 'completed', 'cancelled', 'Package PKG-20260927-741F is cancelled', '2026-10-07 04:05:35'),
(63, '3.7', 'house_bookings', 59, 'PKG-20260928-F864-H', 'booking_status', 'pending', 'confirmed', 'Package PKG-20260928-F864 is confirmed', '2026-10-07 04:05:35'),
(64, '3.7', 'tour_bookings', 18, 'PKG-20260928-F864-T', 'booking_status', 'pending', 'confirmed', 'Package PKG-20260928-F864 is confirmed', '2026-10-07 04:05:35'),
(65, '3.7', 'food_bookings', 17, 'PKG-20260928-F864-F', 'booking_status', 'pending', 'confirmed', 'Package PKG-20260928-F864 is confirmed', '2026-10-07 04:05:35'),
(66, '3.7', 'house_bookings', 59, 'PKG-20260928-F864-H', 'payment_status', 'paid', 'reservation_paid', 'Mirror package PKG-20260928-F864 payment state (component carries no money)', '2026-10-07 04:05:35'),
(67, '3.7', 'tour_bookings', 18, 'PKG-20260928-F864-T', 'payment_status', 'paid', 'reservation_paid', 'Mirror package PKG-20260928-F864 payment state (component carries no money)', '2026-10-07 04:05:35'),
(68, '3.7', 'food_bookings', 17, 'PKG-20260928-F864-F', 'payment_status', 'paid', 'reservation_paid', 'Mirror package PKG-20260928-F864 payment state (component carries no money)', '2026-10-07 04:05:35'),
(69, '3.8', 'house_bookings', 25, 'HS-20260908-1012', 'completed_at', NULL, '2026-09-23 12:00:00.000000', 'Backfilled from check-out date/time', '2026-10-07 04:05:35'),
(70, '3.8', 'house_bookings', 27, 'HS-20260911-4085', 'completed_at', NULL, '2026-09-24 12:00:00.000000', 'Backfilled from check-out date/time', '2026-10-07 04:05:35'),
(71, '3.8', 'house_bookings', 29, 'HS-20260914-3630', 'completed_at', NULL, '2026-09-25 12:00:00.000000', 'Backfilled from check-out date/time', '2026-10-07 04:05:35'),
(72, '3.8', 'house_bookings', 33, 'HS-20260916-6124', 'completed_at', NULL, '2026-09-26 12:00:00.000000', 'Backfilled from check-out date/time', '2026-10-07 04:05:35'),
(73, '3.8', 'tour_bookings', 1, 'TOUR-20260910-1001', 'completed_at', NULL, '2026-09-25 17:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(74, '3.8', 'tour_bookings', 2, 'TOUR-20260910-8996', 'completed_at', NULL, '2026-09-25 17:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(75, '3.8', 'tour_bookings', 3, 'TOUR-20260910-8539', 'completed_at', NULL, '2026-09-18 07:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(76, '3.8', 'tour_bookings', 4, 'TOUR-20260911-5949', 'completed_at', NULL, '2026-09-26 08:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(77, '3.8', 'tour_bookings', 5, 'TOUR-20260916-7043', 'completed_at', NULL, '2026-09-24 07:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(78, '3.8', 'tour_bookings', 6, 'TOUR-20260916-1549', 'completed_at', NULL, '2026-09-22 07:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(79, '3.8', 'tour_bookings', 7, 'TOUR-20260916-4897', 'completed_at', NULL, '2026-09-17 07:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(80, '3.8', 'tour_bookings', 8, 'TOUR-20260916-3670', 'completed_at', NULL, '2026-09-23 08:00:00.000000', 'Backfilled from tour date', '2026-10-07 04:05:35'),
(81, '3.8', 'food_bookings', 1, 'FOOD-20260910-4498', 'completed_at', NULL, '2026-09-25 14:00:00.000000', 'Backfilled from preferred (service) date', '2026-10-07 04:05:35'),
(82, '3.8', 'food_bookings', 2, 'FOOD-20260911-8206', 'completed_at', NULL, '2026-09-26 09:00:00.000000', 'Backfilled from preferred (service) date', '2026-10-07 04:05:35'),
(83, '3.8', 'food_bookings', 16, 'PKG-20260927-4CAA-F', 'completed_at', NULL, '2026-09-28 11:00:00.000000', 'Backfilled from preferred (service) date', '2026-10-07 04:05:35'),
(84, '3.10', 'site_content', 33, 'social.facebook', 'row', 'https://www.facebook.com/share/19ZYXz3q62/', NULL, 'Duplicate key removed; kept id 34', '2026-10-07 04:05:35'),
(85, '3.10', 'site_content', 38, 'terms.body', 'content_value', 'Welcome to Transient House & Tours. By booking with us, you agree to the following terms:\r\n\r\n1. BOOKING & RESERVATIONS\r\n• All bookings are subject to availability and confirmation.\r\n• A valid government-issued ID is required during check-in.\r\n• The lead guest must be at least 18 years old.\r\n\r\n2. PAYMENT TERMS\r\n• A down payment may be required to confirm your reservation.\r\n• Full payment must be settled before check-in unless otherwise agreed.\r\n• GCash reference numbers must be provided when uploading payment proof.\r\n\r\n3. CANCELLATION & REFUNDS\r\n• Cancellations made 7 days before check-in are eligible for a refund.\r\n• Cancellations within 7 days are non-refundable but may be rebooked once.\r\n• No-shows will be charged the full amount.\r\n\r\n4. HOUSE RULES\r\n• No smoking inside the premises.\r\n• No pets allowed unless pre-approved.\r\n• Keep noise levels reasonable, especially at night.\r\n• Guests are responsible for any damages to property.\r\n\r\n5. TOURS & ACTIVITIES\r\n• Tour schedules may change due to weather or safety concerns.\r\n• Guests must follow all safety instructions from our staff.\r\n• We are not liable for personal items lost during tours.\r\n\r\n6. LIABILITY\r\n• We are not responsible for accidents caused by negligence.\r\n• Guests participate in activities at their own risk.\r\n\r\n7. CHANGES TO TERMS\r\n• We reserve the right to update these terms at any time.\r\n• Continued use of our services means you accept the updated terms.', '(new payment & rebooking terms)', 'Terms stated cash refunds; business policy is a non-refundable ₱1,000 reservation fee with rebooking', '2026-10-07 04:05:35'),
(86, '3.11', 'users', 7, 'yoanna', 'password', '(not hashed - value not copied)', '(disabled - reset required)', 'Legacy unhashed password disabled; account must reset password', '2026-10-07 04:05:35'),
(87, '3.12b', 'blocked_dates', 5, 'HS-20260923-2571', 'block_date', NULL, '2026-10-22', 'Night of a confirmed stay was not blocked (now owned by this booking)', '2026-10-07 04:05:35'),
(88, '3.12b', 'blocked_dates', 6, 'PKG-20260927-4CAA-H', 'block_date', NULL, '2026-10-29', 'Night of a confirmed stay was not blocked (now owned by this booking)', '2026-10-07 04:05:35'),
(89, '3.12b', 'blocked_dates', 4, 'PKG-20260928-F864-H', 'block_date', NULL, '2026-10-29', 'Night of a confirmed stay was not blocked (now owned by this booking)', '2026-10-07 04:05:35'),
(90, '3.12b', 'blocked_dates', 4, 'PKG-20260928-F864-H', 'block_date', NULL, '2026-10-30', 'Night of a confirmed stay was not blocked (now owned by this booking)', '2026-10-07 04:05:35');

-- --------------------------------------------------------

--
-- Table structure for table `migration_2026_10_owner_review`
--

CREATE TABLE `migration_2026_10_owner_review` (
  `id` int(11) NOT NULL,
  `category` varchar(60) NOT NULL,
  `booking_type` varchar(20) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `reference_number` varchar(60) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `details` varchar(500) NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `migration_2026_10_owner_review`
--

INSERT INTO `migration_2026_10_owner_review` (`id`, `category`, `booking_type`, `record_id`, `reference_number`, `total_amount`, `amount_paid`, `details`, `resolved_at`, `created_at`) VALUES
(1, 'food_category_check', 'food_item', 1, 'BILAO PACKAGES', NULL, NULL, 'Category was empty; set to \"bilao\" from the item name. Change it in Food Management if another category is intended.', NULL, '2026-10-07 04:05:35'),
(2, 'food_category_check', 'food_item', 3, 'BOODLE FIGHT', NULL, NULL, 'Category was empty; set to \"boodle_regular\" from the item name. Change it in Food Management if another category is intended.', NULL, '2026-10-07 04:05:35'),
(4, 'legacy_rebook_record', 'house', 28, 'RE-20260911-2535', 4000.00, NULL, 'Legacy rebooking record linked to booking HS-20260911-4085. Payment status was \"paid\" with its own payment proof. Not counted as a separate sale or payment. If the guest really paid again for this record, record it manually.', NULL, '2026-10-07 04:05:35'),
(5, 'completed_balance_unverified', 'house', 25, 'HS-20260908-1012', 5500.00, 1000.00, 'Stay completed. Only the reservation fee is recorded; balance of 4500.00 may have been paid on arrival but is not recorded. Use \"Mark Balance as Paid\" if it was received.', NULL, '2026-10-07 04:05:35'),
(6, 'completed_balance_unverified', 'house', 27, 'HS-20260911-4085', 4000.00, 1000.00, 'Stay completed. Only the reservation fee is recorded; balance of 3000.00 may have been paid on arrival but is not recorded. Use \"Mark Balance as Paid\" if it was received.', NULL, '2026-10-07 04:05:35'),
(7, 'completed_balance_unverified', 'house', 29, 'HS-20260914-3630', 12000.00, 1000.00, 'Stay completed. Only the reservation fee is recorded; balance of 11000.00 may have been paid on arrival but is not recorded. Use \"Mark Balance as Paid\" if it was received.', NULL, '2026-10-07 04:05:35'),
(8, 'completed_balance_unverified', 'house', 33, 'HS-20260916-6124', 2000.00, 1000.00, 'Stay completed. Only the reservation fee is recorded; balance of 1000.00 may have been paid on arrival but is not recorded. Use \"Mark Balance as Paid\" if it was received.', NULL, '2026-10-07 04:05:35'),
(9, 'completed_balance_unverified', 'tour', 2, 'TOUR-20260910-8996', 3000.00, 1000.00, 'Tour completed. Only the reservation fee is recorded; balance of 2000.00 not recorded.', NULL, '2026-10-07 04:05:35'),
(10, 'completed_balance_unverified', 'tour', 4, 'TOUR-20260911-5949', 4000.00, 1000.00, 'Tour completed. Only the reservation fee is recorded; balance of 3000.00 not recorded.', NULL, '2026-10-07 04:05:35'),
(11, 'completed_balance_unverified', 'tour', 6, 'TOUR-20260916-1549', 4000.00, 1000.00, 'Tour completed. Only the reservation fee is recorded; balance of 3000.00 not recorded.', NULL, '2026-10-07 04:05:35'),
(12, 'completed_balance_unverified', 'food', 2, 'FOOD-20260911-8206', 1500.00, 1000.00, 'Order completed. Only the reservation fee is recorded; balance of 500.00 not recorded.', NULL, '2026-10-07 04:05:35'),
(20, 'completed_unpaid', 'tour', 1, 'TOUR-20260910-1001', 3000.00, 0.00, 'Marked completed but no payment is recorded', NULL, '2026-10-07 04:05:35'),
(21, 'completed_unpaid', 'tour', 3, 'TOUR-20260910-8539', 3000.00, 0.00, 'Marked completed but no payment is recorded', NULL, '2026-10-07 04:05:35'),
(22, 'completed_unpaid', 'tour', 5, 'TOUR-20260916-7043', 2880.00, 0.00, 'Marked completed but no payment is recorded', NULL, '2026-10-07 04:05:35'),
(23, 'completed_unpaid', 'tour', 7, 'TOUR-20260916-4897', 4000.00, 0.00, 'Marked completed but no payment is recorded', NULL, '2026-10-07 04:05:35'),
(24, 'completed_unpaid', 'tour', 8, 'TOUR-20260916-3670', 4000.00, 0.00, 'Marked completed but no payment is recorded', NULL, '2026-10-07 04:05:35'),
(25, 'completed_unpaid', 'food', 1, 'FOOD-20260910-4498', 1500.00, 0.00, 'Marked completed but no payment is recorded', NULL, '2026-10-07 04:05:35'),
(27, 'rebooking_credit', 'house', 16, 'HS-20260816-2228', 11000.00, 1000.00, 'Paid reservation that was cancelled. The reservation fee is kept as a rebooking credit (non-refundable).', NULL, '2026-10-07 04:05:35'),
(28, 'rebooking_credit', 'house', 17, 'HS-20260831-7897', 11000.00, 1000.00, 'Paid reservation that was cancelled. The reservation fee is kept as a rebooking credit (non-refundable).', NULL, '2026-10-07 04:05:35'),
(29, 'rebooking_credit', 'house', 18, 'HS-20260903-7958', 11000.00, 1000.00, 'Paid reservation that was cancelled. The reservation fee is kept as a rebooking credit (non-refundable).', NULL, '2026-10-07 04:05:35'),
(30, 'rebooking_credit', 'house', 24, 'HS-20260908-2990', 11000.00, 1000.00, 'Paid reservation that was cancelled. The reservation fee is kept as a rebooking credit (non-refundable).', NULL, '2026-10-07 04:05:35'),
(31, 'rebooking_credit', 'house', 32, 'HS-20260915-9520', 6000.00, 1000.00, 'Paid reservation that was cancelled. The reservation fee is kept as a rebooking credit (non-refundable).', NULL, '2026-10-07 04:05:35'),
(34, 'payment_date_missing', 'house', 32, 'HS-20260915-9520', NULL, 1000.00, 'Payment was confirmed but the confirmation date was never recorded. It is not placed in any report month.', NULL, '2026-10-07 04:05:35'),
(35, 'cancelled_payment_with_timestamp', 'house', 14, 'HS-20260814-4537', 11000.00, NULL, 'Payment status is cancelled but paid_at = 2026-08-14 05:51:06. Verify whether money was received.', NULL, '2026-10-07 04:05:35'),
(36, 'cancelled_payment_with_timestamp', 'house', 26, 'HS-20260908-5828', 11000.00, NULL, 'Payment status is cancelled but paid_at = 2026-09-08 03:44:20. Verify whether money was received.', NULL, '2026-10-07 04:05:35'),
(38, 'gcash_placeholder', NULL, NULL, NULL, NULL, NULL, 'GCash account_name still looks like a placeholder. Enter the real value in Edit Content > GCash.', NULL, '2026-10-07 04:05:35'),
(39, 'gcash_placeholder', NULL, NULL, NULL, NULL, NULL, 'GCash number still looks like a placeholder. Enter the real value in Edit Content > GCash.', NULL, '2026-10-07 04:05:35'),
(41, 'password_reset_required', 'staff', 7, 'yoanna', NULL, NULL, 'Password was stored without hashing and has been disabled. Use Forgot Password or set a new password in User Management.', NULL, '2026-10-07 04:05:35');

-- --------------------------------------------------------

--
-- Table structure for table `overall_feedback`
--

CREATE TABLE `overall_feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `overall_feedback`
--

INSERT INTO `overall_feedback` (`id`, `user_id`, `rating`, `comment`, `created_at`, `updated_at`, `is_anonymous`) VALUES
(4, 8, 4, 'good', '2026-09-11 14:41:41', '2026-09-16 05:39:54', 1),
(5, 9, 4, 'HAAHHah', '2026-09-21 17:31:14', '2026-09-27 13:33:48', 0);

-- --------------------------------------------------------

--
-- Table structure for table `package_bookings`
--

CREATE TABLE `package_bookings` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `house_booking_id` int(11) DEFAULT NULL,
  `tour_booking_id` int(11) DEFAULT NULL,
  `food_booking_id` int(11) DEFAULT NULL,
  `items_json` longtext DEFAULT NULL,
  `house_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tour_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `food_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reservation_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `contact_number` varchar(20) DEFAULT NULL,
  `payment_status` enum('pending','reservation_paid','paid','cancelled') NOT NULL DEFAULT 'pending',
  `booking_status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `special_requests` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `gcash_reference` varchar(30) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `reservation_paid_at` datetime DEFAULT NULL,
  `balance_paid_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `rebook_count` int(11) NOT NULL DEFAULT 0,
  `rebooked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `package_bookings`
--

INSERT INTO `package_bookings` (`id`, `reference_number`, `guest_id`, `house_booking_id`, `tour_booking_id`, `food_booking_id`, `items_json`, `house_amount`, `tour_amount`, `food_amount`, `grand_total`, `reservation_fee_amount`, `amount_paid`, `contact_number`, `payment_status`, `booking_status`, `special_requests`, `created_at`, `updated_at`, `gcash_reference`, `payment_proof`, `proof_uploaded_at`, `reservation_paid_at`, `balance_paid_at`, `cancelled_at`, `cancellation_reason`, `completed_at`, `rebook_count`, `rebooked_at`) VALUES
(1, 'PKG-20260926-9C97', 5, 38, 14, 5, NULL, 4000.00, 3000.00, 1500.00, 8500.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: no spicy\nTour: safe ride', '2026-09-26 19:54:33', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:48:16', NULL, NULL, 0, NULL),
(2, 'PKG-20260926-BB5E', 5, 39, NULL, 6, NULL, 5500.00, 0.00, 1500.00, 7000.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: asdfasdf', '2026-09-26 20:01:57', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:48:09', NULL, NULL, 0, NULL),
(3, 'PKG-20260926-5FBC', 5, 40, NULL, 8, NULL, 4000.00, 0.00, 1500.00, 5500.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: sdgfsdfgsdfg', '2026-09-26 20:14:50', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:48:04', NULL, NULL, 0, NULL),
(4, 'PKG-20260926-AAD9', 5, 41, NULL, 9, NULL, 2000.00, 0.00, 4000.00, 6000.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: zsfdsdf', '2026-09-26 20:16:06', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:47:58', NULL, NULL, 0, NULL),
(5, 'PKG-20260926-E2D4', 5, 42, NULL, 10, NULL, 12000.00, 0.00, 1500.00, 13500.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: asdfasdf', '2026-09-26 20:32:58', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:47:42', NULL, NULL, 0, NULL),
(6, 'PKG-20260926-8C83', 5, 43, NULL, 11, NULL, 2000.00, 0.00, 4000.00, 6000.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: ghhfghfgh', '2026-09-26 20:46:54', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 20:47:19', NULL, NULL, 0, NULL),
(7, 'PKG-20260926-F8E2', 5, 44, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:49:05', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:50', NULL, NULL, 0, NULL),
(8, 'PKG-20260926-7F11', 5, 45, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:49:38', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:45', NULL, NULL, 0, NULL),
(9, 'PKG-20260926-02DD', 5, 46, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:50:03', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:40', NULL, NULL, 0, NULL),
(10, 'PKG-20260926-16E3', 5, 47, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:50:31', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:35', NULL, NULL, 0, NULL),
(11, 'PKG-20260926-0960', 5, 48, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:50:54', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:31', NULL, NULL, 0, NULL),
(12, 'PKG-20260926-6CA2', 5, 49, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 20:51:24', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:26', NULL, NULL, 0, NULL),
(13, 'PKG-20260926-F6B8', 5, 50, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:07', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:20', NULL, NULL, 0, NULL),
(14, 'PKG-20260926-52AE', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:12', '2026-09-26 21:07:15', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:07:15', NULL, NULL, 0, NULL),
(15, 'PKG-20260926-1C4E', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:33', '2026-09-26 21:07:10', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:07:10', NULL, NULL, 0, NULL),
(16, 'PKG-20260926-8B41', 5, 51, NULL, NULL, NULL, 2000.00, 0.00, 0.00, 2000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:05:54', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:07:02', NULL, NULL, 0, NULL),
(17, 'PKG-20260926-F39F', 5, 52, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 21:18:37', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-26 21:21:01', NULL, NULL, 0, NULL),
(18, 'PKG-20260926-5633', 5, 53, NULL, NULL, NULL, 6000.00, 0.00, 0.00, 6000.00, 1000.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 22:17:48', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-27 08:29:39', NULL, NULL, 0, NULL),
(19, 'PKG-20260926-AB28', 5, 54, NULL, 12, NULL, 6000.00, 0.00, 4000.00, 10000.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: fdgdfg', '2026-09-26 22:19:17', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-27 08:29:33', NULL, NULL, 0, NULL),
(20, 'PKG-20260926-173F', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 22:46:10', '2026-09-27 08:29:26', NULL, NULL, NULL, NULL, NULL, '2026-09-27 08:29:26', NULL, NULL, 0, NULL),
(21, 'PKG-20260926-EB35', 5, 55, 16, 13, NULL, 2000.00, 3000.00, 4000.00, 9000.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Tour: asdasda', '2026-09-26 22:47:07', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-27 08:29:21', NULL, NULL, 0, NULL),
(22, 'PKG-20260926-E5F7', 5, NULL, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '09505241711', 'pending', 'cancelled', NULL, '2026-09-26 23:17:47', '2026-09-27 08:29:15', NULL, NULL, NULL, NULL, NULL, '2026-09-27 08:29:15', NULL, NULL, 0, NULL),
(23, 'PKG-20260926-FB6D', 5, 56, NULL, 14, NULL, 6000.00, 0.00, 1500.00, 7500.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', NULL, '2026-09-26 23:19:56', '2026-10-07 12:05:35', NULL, NULL, NULL, NULL, NULL, '2026-09-27 08:29:09', NULL, NULL, 0, NULL),
(24, 'PKG-20260927-741F', 5, 57, 17, 15, NULL, 6000.00, 3000.00, 1500.00, 10500.00, 1000.00, 0.00, '+639505241711', 'pending', 'cancelled', 'Food: asdasd', '2026-09-27 09:18:46', '2026-10-07 12:05:35', '0045077587865', '1790473953_PKG-20260927-741F.png', '2026-09-27 09:52:33', NULL, NULL, '2026-09-29 05:48:18', NULL, NULL, 0, NULL),
(25, 'PKG-20260927-4CAA', 5, 58, NULL, 16, NULL, 2000.00, 0.00, 1500.00, 3500.00, 1000.00, 0.00, '+639505241711', 'pending', 'pending', 'Food: adsasd', '2026-09-27 15:48:52', '2026-10-07 12:05:35', '0045077587865', '1790495365_PKG-20260927-4CAA.png', '2026-09-27 15:49:25', NULL, NULL, NULL, NULL, NULL, 0, NULL),
(26, 'PKG-20260928-F864', 5, 59, 18, 17, NULL, 11000.00, 3000.00, 1500.00, 15500.00, 1000.00, 1000.00, '+639505241711', 'reservation_paid', 'confirmed', 'Food: wala\nTour: wala', '2026-09-29 05:47:13', '2026-10-07 12:05:35', '23423324234234234234', '1790632071_PKG-20260928-F864.png', '2026-09-29 05:47:51', '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `package_items`
--

CREATE TABLE `package_items` (
  `id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `food_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_content`
--

CREATE TABLE `site_content` (
  `id` int(11) NOT NULL,
  `section_name` varchar(100) NOT NULL,
  `content_key` varchar(100) NOT NULL,
  `content_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_content`
--

INSERT INTO `site_content` (`id`, `section_name`, `content_key`, `content_value`, `created_at`) VALUES
(1, 'hero', 'title', 'Welcome to Transient House & Tours', '2026-07-09 01:25:44'),
(2, 'hero', 'subtitle', 'Your home away from home and gateway to unforgettable island adventures.', '2026-07-09 01:25:44'),
(3, 'hero', 'stats_houses_label', 'Total Houses', '2026-07-09 01:25:44'),
(4, 'hero', 'stats_houses_available_label', 'Houses Available', '2026-07-09 01:25:44'),
(5, 'hero', 'stats_tours_label', 'Tour Packages', '2026-07-09 01:25:44'),
(6, 'hero', 'stats_tours_available_label', 'Tours Available', '2026-07-09 01:25:44'),
(7, 'features', 'section_title', 'Why Choose Us', '2026-07-09 01:25:44'),
(8, 'features', 'feature1_title', 'Comfortable Houses', '2026-07-09 01:25:44'),
(9, 'features', 'feature1_desc', 'Experience true comfort in our well-appointed transient houses.', '2026-07-09 01:25:44'),
(10, 'features', 'feature2_title', 'Island Tours', '2026-07-09 01:25:44'),
(11, 'features', 'feature2_desc', 'Explore the beautiful islands with our exciting tour packages.', '2026-07-09 01:25:44'),
(12, 'features', 'feature3_title', 'Secure Booking', '2026-07-09 01:25:44'),
(13, 'features', 'feature3_desc', 'Your transactions are safe and secure with our encrypted booking system.', '2026-07-09 01:25:44'),
(14, 'features', 'feature4_title', '24/7 Support', '2026-07-09 01:25:44'),
(15, 'features', 'feature4_desc', 'We\'re always here to help you with any questions or concerns.', '2026-07-09 01:25:44'),
(16, 'cta', 'title', 'Ready to Book Your Stay?', '2026-07-09 01:25:44'),
(17, 'cta', 'subtitle', 'Choose from our comfortable houses or exciting tour packages for your next adventure.', '2026-07-09 01:25:44'),
(18, 'cta', 'button_houses_text', 'Browse Houses', '2026-07-09 01:25:44'),
(19, 'cta', 'button_tours_text', 'Browse Tours', '2026-07-09 01:25:44'),
(20, 'footer', 'company_description', 'Your trusted partner for comfortable accommodations and exciting island adventures.', '2026-07-09 01:25:44'),
(21, 'footer', 'address', ' Inansuana, Lucap, Alaminos, Philippines, 2404', '2026-07-09 01:25:44'),
(22, 'footer', 'phone', ' 0961 838 0969', '2026-07-09 01:25:44'),
(23, 'footer', 'email', 'info@transientrental.com', '2026-07-09 01:25:44'),
(24, 'footer', 'copyright', 'Transient House & Tours. All rights reserved.', '2026-07-09 01:25:44'),
(25, 'footer', 'privacy_policy', 'Privacy Policy', '2026-07-09 01:25:44'),
(26, 'footer', 'terms_of_service', 'Terms of Service', '2026-07-09 01:25:44'),
(27, 'gcash', 'account_name', 'Juan Dela Cruz', '2026-07-09 01:25:44'),
(28, 'gcash', 'number', '09123456789', '2026-07-09 01:25:44'),
(29, 'gcash', 'qr_code', 'gcash_qr.jpg', '2026-07-09 01:25:44'),
(30, 'gcash', 'instructions', '1. Open GCash app\r\n2. Click \'Pay QR\' or \'Scan QR\'\r\n3. Scan the QR code above\r\n4. Enter the exact amount shown\r\n5. Complete the payment\r\n6. Take a screenshot of the transaction\r\n7. Upload screenshot as proof of payment', '2026-07-09 01:25:44'),
(31, 'site_settings', 'logo_path', 'uploads/logos/logo.png', '2026-07-30 04:36:36'),
(32, 'site_settings', 'hero_image_path', 'uploads/hero/hero-bg.jpg', '2026-08-02 01:30:29'),
(34, 'social', 'facebook', 'https://www.facebook.com/share/19ZYXz3q62/', '2026-09-08 11:34:53'),
(35, 'location', 'address', 'Inansuana, Lucap, Alaminos, Philippines, 2404', '2026-09-08 13:10:25'),
(36, 'location', 'google_maps_embed', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d297.1619295453863!2d120.00277185108841!3d16.188971237180805!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3393db0027bc4d99%3A0xcd09fc5b0c6e96b6!2sClarissa%20Dino-Fernandez%20Tour%20Reservation%20and%20Transient%20House!5e1!3m2!1sen!2sph!4v1788872926142!5m2!1sen!2sph\" width=\"600\" height=\"450\" style=\"border:0;\" allowfullscreen=\"\" loading=\"lazy\" referrerpolicy=\"strict-origin-when-cross-origin\">', '2026-09-08 13:10:25'),
(37, 'terms', 'title', 'Terms & Conditions', '2026-09-16 02:15:33'),
(38, 'terms', 'body', 'Welcome to Transient House & Tours. By booking with us, you agree to the following terms:\r\n\r\n1. BOOKING & RESERVATIONS\r\n• All bookings are subject to availability and confirmation.\r\n• A valid government-issued ID is required during check-in.\r\n• The lead guest must be at least 18 years old.\r\n\r\n2. PAYMENT TERMS\r\n• A ₱1,000 reservation fee is required to secure every House, Tour, Food or Package booking.\r\n• The reservation fee forms part of the total booking amount.\r\n• The remaining balance is payable upon arrival/check-in, or before the booked service begins.\r\n• GCash reference numbers must be provided when uploading proof of the reservation fee.\r\n• Bookings without a confirmed reservation fee may be cancelled by the owner.\r\n\r\n3. CANCELLATION & REBOOKING\r\n• Reservation fees and amounts paid are non-refundable.\r\n• Unpaid reservations may be cancelled at no charge.\r\n• House, Tour, Food and Package bookings that are confirmed or cancelled with money received may be rebooked, subject to availability. Completed bookings cannot be rebooked.\r\n• Maximum of 2 rebooks per booking, within 7 days from booking date.\r\n• The amount already paid is carried forward; no second reservation fee is charged and no refund is given.\r\n• House rebooks keep the same number of nights and need admin approval.\r\n• No-shows forfeit the reservation fee.\r\n\r\n4. HOUSE RULES\r\n• No smoking inside the premises.\r\n• No pets allowed unless pre-approved.\r\n• Keep noise levels reasonable, especially at night.\r\n• Guests are responsible for any damages to property.\r\n\r\n5. TOURS & ACTIVITIES\r\n• Tour schedules may change due to weather or safety concerns.\r\n• Guests must follow all safety instructions from our staff.\r\n• We are not liable for personal items lost during tours.\r\n\r\n6. LIABILITY\r\n• We are not responsible for accidents caused by negligence.\r\n• Guests participate in activities at their own risk.\r\n\r\n7. CHANGES TO TERMS\r\n• We reserve the right to update these terms at any time.\r\n• Continued use of our services means you accept the updated terms.', '2026-09-16 02:15:33'),
(39, 'privacy', 'title', 'Privacy Policy', '2026-09-16 02:15:33'),
(40, 'privacy', 'body', 'Your privacy is important to us. This policy explains what information we collect and how we use it.\r\n\r\n1. INFORMATION WE COLLECT\r\n• Personal details: name, contact number, email, address.\r\n• Government-issued ID for verification.\r\n• Payment proof (GCash reference, screenshots).\r\n• Health information you voluntarily provide (allergies, medical conditions).\r\n\r\n2. HOW WE USE YOUR INFORMATION\r\n• To process bookings and reservations.\r\n• To verify your identity during check-in.\r\n• To contact you regarding your booking.\r\n• To improve our services and customer experience.\r\n\r\n3. DATA SHARING\r\n• We do NOT sell your personal information to third parties.\r\n• Information may be shared with tour operators only when necessary.\r\n• We may disclose information if required by law.\r\n\r\n4. DATA SECURITY\r\n• We take reasonable measures to protect your data.\r\n• Access is limited to authorized staff only.\r\n• However, no system is 100% secure — use at your own risk.\r\n\r\n5. YOUR RIGHTS\r\n• You may request a copy of your data.\r\n• You may request corrections to your information.\r\n• You may request deletion of your account (subject to legal requirements).\r\n\r\n6. COOKIES\r\n• We use session cookies to keep you logged in.\r\n• No third-party tracking cookies are used.\r\n\r\n7. CHILDREN\'S PRIVACY\r\n• Our services are not intended for children under 18 without parental consent.\r\n\r\n8. CONTACT US\r\n• For privacy concerns, please contact us using the details in the footer.\r\n\r\nBy using our services, you agree to this privacy policy.', '2026-09-16 02:15:33');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `role` varchar(20) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `status` enum('success','failed','warning') DEFAULT 'success',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`id`, `user_id`, `username`, `fullname`, `role`, `action`, `module`, `description`, `target_id`, `target_type`, `old_values`, `new_values`, `ip_address`, `user_agent`, `status`, `created_at`) VALUES
(1, 1, 'admin', NULL, 'admin', 'update', 'profile', 'Updated own profile (name & email)', 1, 'user', '{\"fullname\":\"System Administrator\",\"email\":\"johnpaulnavarro0105@gmail.com\"}', '{\"fullname\":\"Joh\",\"email\":\"johnpaulnavarro0105@gmail.com\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', 'success', '2026-09-23 19:05:11'),
(2, 1, 'admin', NULL, 'admin', 'update', 'profile', 'Updated own profile (name & email)', 1, 'user', '{\"fullname\":\"Joh\",\"email\":\"johnpaulnavarro0105@gmail.com\"}', '{\"fullname\":\"Joh\",\"email\":\"johnpaulnavarro0105@gmail.com\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', 'success', '2026-09-23 19:05:15'),
(3, 1, 'admin', NULL, 'admin', 'update', 'profile', 'Updated own profile (name & email)', 1, 'user', '{\"fullname\":\"Joh\",\"email\":\"johnpaulnavarro0105@gmail.com\"}', '{\"fullname\":\"John Paul Navarro\",\"email\":\"johnpaulnavarro0105@gmail.com\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', 'success', '2026-09-23 19:05:33'),
(4, 1, 'admin', 'John Paul Navarro', 'admin', 'reject_payment', 'booking', 'Rejected house booking HS-20260916-6285 — Reason: GCash reference number does not match', 34, 'booking', '{\"reference\":\"HS-20260916-6285\",\"amount\":\"6000.00\"}', '{\"reason\":\"GCash reference number does not match\",\"rejected_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-23 21:08:38'),
(5, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', 'success', '2026-09-24 08:22:19'),
(6, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'unknown', 'booking', 'Guest \'Rinn\' attempted to rebook booking #33 but was blocked — Reason: Rebook option expired. You can only rebook within 7 days from your booking date.', 33, 'house_booking', NULL, '{\"reason\":\"Rebook option expired. You can only rebook within 7 days from your booking date.\",\"ip\":\"::1\",\"page\":\"rebook.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'failed', '2026-09-24 09:52:40'),
(7, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for house booking HS-20260923-2571 — GCash Ref: 0045077587865', 36, 'house_booking', NULL, '{\"gcash_reference\":\"0045077587865\",\"filename\":\"1790215779_HS-20260923-2571.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-24 10:09:49'),
(8, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for house booking HS-20260923-2571 — GCash Ref: 0045077587865', 36, 'house_booking', NULL, '{\"gcash_reference\":\"0045077587865\",\"filename\":\"1790215789_HS-20260923-2571.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-24 10:09:57'),
(9, 1, 'admin', 'John Paul Navarro', 'admin', 'confirm_payment', 'booking', 'Confirmed house payment for booking ID 36', 36, 'booking', NULL, NULL, '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 10:40:19'),
(10, 1, 'admin', 'John Paul Navarro', 'admin', 'confirm_payment', 'booking', 'Confirmed house payment for booking ID 36', 36, 'booking', NULL, NULL, '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 10:40:23'),
(11, 1, 'admin', 'John Paul Navarro', 'admin', 'reject_payment', 'booking', 'Rejected house booking HS-20260915-7123 — Reason: Invalid or unclear payment proof', 31, 'booking', '{\"reference\":\"HS-20260915-7123\",\"amount\":\"2000.00\"}', '{\"reason\":\"Invalid or unclear payment proof\",\"rejected_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-24 11:41:07'),
(12, 1, 'admin', 'John Paul Navarro', 'admin', 'reject_payment', 'booking', 'Rejected house booking HS-20260915-9588 — Reason: Invalid or unclear payment proof', 30, 'booking', '{\"reference\":\"HS-20260915-9588\",\"amount\":\"6000.00\"}', '{\"reason\":\"Invalid or unclear payment proof\",\"rejected_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-24 11:41:24'),
(13, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Rejected rebook for house booking HS-20260914-3630 — Reason: Conflicts with existing booking', 29, 'house_booking', '{\"reference\":\"HS-20260914-3630\",\"reverted_check_in\":\"2026-09-23\"}', '{\"reason\":\"Conflicts with existing booking\",\"rejected_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-24 12:11:13'),
(14, 1, 'admin', 'John Paul Navarro', 'admin', 'create', 'user', 'Added new user: paul (staff)', 11, 'user', NULL, '{\"username\":\"paul\",\"email\":\"johnpaulnavarro146@gmail.com\",\"fullname\":\"john paul navarro\",\"role\":\"staff\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 12:42:32'),
(15, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 13:03:23'),
(16, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'paul\' logged in from NEW device — OTP sent', 11, 'user', NULL, '{\"role\":\"staff\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 13:03:37'),
(17, 11, 'paul', 'john paul navarro', 'staff', 'verify_device', 'auth', 'User \'paul\' successfully verified a NEW device and logged in — IP: ::1', 11, 'user', NULL, '{\"role\":\"staff\",\"device_name\":\"Mozilla\\/5.0 (Linux; Android 15; Pixel 9) AppleWebKit\\/537.36 (KHTML, like Gecko) Edg\\/153.0.0.0 Mobile Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-24 07:04:33\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 13:04:33'),
(18, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'rebook', 'booking', 'Guest \'Rinn\' rebooked house booking \'HS-20260923-2571\' (UNIT 2) — new dates: 2026-10-22 to 2026-10-23 (1 nights, 2 pax, ₱12,000.00) — Rebook #1/2', 36, 'house_booking', '{\"old_check_in\":\"2026-10-23\",\"old_check_out\":\"2026-10-24\",\"old_guests\":2,\"old_total\":\"6000.00\"}', '{\"new_check_in\":\"2026-10-22\",\"new_check_out\":\"2026-10-23\",\"new_guests\":\"2\",\"new_total\":12000,\"rebook_count\":1,\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-24 13:49:27'),
(19, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'unknown', 'booking', 'Guest \'Rinn\' attempted to rebook booking #36 but was blocked — Reason: Only confirmed or completed bookings can be rebooked.', 36, 'house_booking', NULL, '{\"reason\":\"Only confirmed or completed bookings can be rebooked.\",\"ip\":\"::1\",\"page\":\"rebook.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'failed', '2026-09-24 13:49:31'),
(20, 11, 'paul', 'john paul navarro', 'staff', 'unknown', 'booking', 'Rejected rebook for house booking HS-20260923-2571 — Reason: Invalid rebook request', 36, 'house_booking', '{\"reference\":\"HS-20260923-2571\",\"reverted_check_in\":\"2026-10-23\"}', '{\"reason\":\"Invalid rebook request\",\"rejected_by\":\"paul\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-24 13:50:20'),
(21, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'rebook', 'booking', 'Guest \'Rinn\' rebooked house booking \'HS-20260923-2571\' (UNIT 2) — new dates: 2026-10-22 to 2026-10-23 (1 nights, 2 pax, ₱12,000.00) — Rebook #1/2', 36, 'house_booking', '{\"old_check_in\":\"2026-10-23\",\"old_check_out\":\"2026-10-24\",\"old_guests\":2,\"old_total\":\"6000.00\"}', '{\"new_check_in\":\"2026-10-22\",\"new_check_out\":\"2026-10-23\",\"new_guests\":\"2\",\"new_total\":12000,\"rebook_count\":1,\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-24 13:51:06'),
(22, 11, 'paul', 'john paul navarro', 'staff', 'confirm_rebook', 'booking', 'Confirmed rebook for house booking ID 36', 36, 'booking', NULL, NULL, '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 13:51:22'),
(23, 11, 'paul', 'john paul navarro', 'staff', 'update', 'profile', 'Uploaded new profile photo (saved to uploads/profile/user_11/)', 11, 'user', NULL, '{\"file\":\"profile_1790230622.png\",\"dir\":\"uploads\\/profile\\/user_11\\/\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 14:17:02'),
(24, 11, 'paul', 'john paul navarro', 'staff', 'update', 'profile', 'Updated own profile (name & email)', 11, 'user', '{\"fullname\":\"john paul navarro\",\"email\":\"johnpaulnavarro146@gmail.com\"}', '{\"fullname\":\"john paul navarro\",\"email\":\"johnpaulnavarro146@gmail.com\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 14:17:09'),
(25, 11, 'paul', 'john paul navarro', 'staff', 'logout', 'auth', 'User \'paul\' logged out', 11, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 15:45:51'),
(26, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 15:46:30'),
(27, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Linux; Android 15; Pixel 9) AppleWebKit\\/537.36 (KHTML, like Gecko) Edg\\/153.0.0.0 Mobile Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-24 09:47:02\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 15:47:02'),
(28, 1, 'admin', 'John Paul Navarro', 'admin', 'update', 'profile', 'Uploaded new profile photo (saved to uploads/profile/user_1/)', 1, 'user', NULL, '{\"file\":\"profile_1790236052.png\",\"dir\":\"uploads\\/profile\\/user_1\\/\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-24 15:47:32'),
(29, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'New tour booking: Mary Anne  (TOUR-20260924-9652) — ₱4,000.00 — 5 pax on Oct 15, 2026', 11, 'tour_booking', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-24 18:45:27'),
(30, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'New tour booking: Mary Anne  (TOUR-20260924-4407) — ₱4,000.00 — 6 pax on Oct 16, 2026', 12, 'tour_booking', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-24 18:58:59'),
(31, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'TOUR-20260924-9652\' (free cancellation)', 11, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-24 20:22:24'),
(32, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', 'success', '2026-09-25 18:43:55'),
(33, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'User \'Rinn\' updated overall review from Packages page — Rating: 3/5', NULL, 'overall_feedback', NULL, '{\"rating\":3,\"anonymous\":0,\"has_comment\":true,\"page\":\"packages.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-25 19:25:17'),
(34, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'delete', 'booking', 'Guest cleared entire package cart (pre-booking stage)', NULL, 'package', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-25 23:27:41'),
(35, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'admin\' (IP: ::1)', 1, 'user', NULL, '{\"username\":\"admin\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-26 11:41:38'),
(36, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-26 11:41:46'),
(37, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'delete', 'booking', 'Guest cleared entire package cart', NULL, 'package', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 12:49:33'),
(38, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 12:51:31'),
(39, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 12:51:40'),
(40, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'User \'Rinn\' updated overall review from Packages page — Rating: 4/5', NULL, 'overall_feedback', NULL, '{\"rating\":4,\"anonymous\":0,\"has_comment\":true,\"page\":\"packages.php\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 18:50:17'),
(41, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'User \'Rinn\' updated overall review from Food page — Rating: 3/5', NULL, 'overall_feedback', NULL, '{\"rating\":3,\"anonymous\":0,\"has_comment\":true,\"page\":\"food.php\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 18:50:24'),
(42, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'User \'Rinn\' updated overall review from Activities page — Rating: 4/5', NULL, 'overall_feedback', NULL, '{\"rating\":4,\"anonymous\":0,\"has_comment\":true,\"page\":\"activities.php\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 18:50:30'),
(43, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'User updated overall feedback (3/5 stars)', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 18:50:36'),
(44, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'User \'Rinn\' updated overall review — Rating: 4/5', NULL, 'overall_feedback', NULL, '{\"rating\":\"4\",\"anonymous\":0,\"has_comment\":true}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 18:50:48'),
(45, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-26 19:17:59'),
(46, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-9C97 — 3 item(s), Total: ₱8,500.00', 1, 'package', NULL, '{\"package_reference\":\"PKG-20260926-9C97\",\"items\":[{\"type\":\"house\",\"id\":38,\"ref\":\"PKG-20260926-9C97-H\",\"name\":\"UNIT 3\"},{\"type\":\"food\",\"id\":5,\"ref\":\"PKG-20260926-9C97-F\",\"name\":\"BILAO PACKAGES\"},{\"type\":\"tour\",\"id\":14,\"ref\":\"PKG-20260926-9C97-T\",\"name\":\"Herein\"}],\"grand_total\":8500}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 19:54:33'),
(47, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-BB5E — 2 item(s), Total: ₱7,000.00', 2, 'package', NULL, '{\"package_reference\":\"PKG-20260926-BB5E\",\"items\":[{\"type\":\"house\",\"id\":39,\"ref\":\"PKG-20260926-BB5E-H\",\"name\":\"UNIT 1\"},{\"type\":\"food\",\"id\":6,\"ref\":\"PKG-20260926-BB5E-F\",\"name\":\"BILAO PACKAGES\"}],\"grand_total\":7000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:01:57'),
(48, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest \'Rinn O Yoshida\' reserved food \'BOODLE FIGHT\' (Ref: FOOD-20260926-7147) — ₱4,000.00 — Size: Small — Pickup', 7, 'food_booking', NULL, '{\"food_id\":3,\"food_name\":\"BOODLE FIGHT\",\"size_variant\":\"Small\",\"preferred_date\":\"2026-10-22\",\"preferred_time\":\"09:00:00\",\"total\":4000,\"reference\":\"FOOD-20260926-7147\",\"guest_id\":5,\"contact_number\":\"+639505241711\",\"fulfillment_method\":\"pickup\",\"delivery_address\":\"\",\"page\":\"food.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:04:02'),
(49, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'New tour booking: Mary Anne  (TOUR-20260926-6671) — ₱4,000.00 — 5 pax on Oct 21, 2026', 15, 'tour_booking', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:04:25'),
(50, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-5FBC — 2 item(s), Total: ₱5,500.00', 3, 'package', NULL, '{\"package_reference\":\"PKG-20260926-5FBC\",\"items\":[{\"type\":\"house\",\"id\":40,\"ref\":\"PKG-20260926-5FBC-H\",\"name\":\"UNIT 3\"},{\"type\":\"food\",\"id\":8,\"ref\":\"PKG-20260926-5FBC-F\",\"name\":\"BILAO PACKAGES\"}],\"grand_total\":5500}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:14:50'),
(51, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-AAD9 — 2 item(s), Total: ₱6,000.00', 4, 'package', NULL, '{\"package_reference\":\"PKG-20260926-AAD9\",\"items\":[{\"type\":\"house\",\"id\":41,\"ref\":\"PKG-20260926-AAD9-H\",\"name\":\"UNIT 3\"},{\"type\":\"food\",\"id\":9,\"ref\":\"PKG-20260926-AAD9-F\",\"name\":\"BOODLE FIGHT\"}],\"grand_total\":6000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:16:06'),
(52, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-E2D4 — 2 item(s), Total: ₱13,500.00', 5, 'package', NULL, '{\"package_reference\":\"PKG-20260926-E2D4\",\"items\":[{\"type\":\"house\",\"id\":42,\"ref\":\"PKG-20260926-E2D4-H\",\"name\":\"UNIT 2\"},{\"type\":\"food\",\"id\":10,\"ref\":\"PKG-20260926-E2D4-F\",\"name\":\"BILAO PACKAGES\"}],\"grand_total\":13500}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:32:58'),
(53, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-8C83 — 2 item(s), Total: ₱6,000.00', 6, 'package', NULL, '{\"package_reference\":\"PKG-20260926-8C83\",\"items\":[{\"type\":\"house\",\"id\":43,\"ref\":\"PKG-20260926-8C83-H\",\"name\":\"UNIT 3\"},{\"type\":\"food\",\"id\":11,\"ref\":\"PKG-20260926-8C83-F\",\"name\":\"BOODLE FIGHT\"}],\"grand_total\":6000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:46:54'),
(54, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-8C83\' (free cancellation)', 6, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 20:47:19'),
(55, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-E2D4\' (free cancellation)', 5, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:47:42'),
(56, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-8C83-H\' (free cancellation)', 43, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:47:52'),
(57, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-AAD9\' (free cancellation)', 4, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:47:58'),
(58, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-5FBC\' (free cancellation)', 3, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:04'),
(59, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-BB5E\' (free cancellation)', 2, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:09'),
(60, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-9C97\' (free cancellation)', 1, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:16'),
(61, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-E2D4-H\' (free cancellation)', 42, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:20'),
(62, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-AAD9-H\' (free cancellation)', 41, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:23'),
(63, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-5FBC-H\' (free cancellation)', 40, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:26'),
(64, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-BB5E-H\' (free cancellation)', 39, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:31'),
(65, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-9C97-H\' (free cancellation)', 38, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 20:48:34'),
(66, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-F8E2 — 1 item(s), Total: ₱2,000.00', 7, 'package', NULL, '{\"package_reference\":\"PKG-20260926-F8E2\",\"items\":[{\"type\":\"house\",\"id\":44,\"ref\":\"PKG-20260926-F8E2-H\",\"name\":\"UNIT 3\"}],\"grand_total\":2000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:49:05'),
(67, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-7F11 — 1 item(s), Total: ₱2,000.00', 8, 'package', NULL, '{\"package_reference\":\"PKG-20260926-7F11\",\"items\":[{\"type\":\"house\",\"id\":45,\"ref\":\"PKG-20260926-7F11-H\",\"name\":\"UNIT 3\"}],\"grand_total\":2000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:49:38'),
(68, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-02DD — 1 item(s), Total: ₱2,000.00', 9, 'package', NULL, '{\"package_reference\":\"PKG-20260926-02DD\",\"items\":[{\"type\":\"house\",\"id\":46,\"ref\":\"PKG-20260926-02DD-H\",\"name\":\"UNIT 3\"}],\"grand_total\":2000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:50:03'),
(69, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-16E3 — 1 item(s), Total: ₱6,000.00', 10, 'package', NULL, '{\"package_reference\":\"PKG-20260926-16E3\",\"items\":[{\"type\":\"house\",\"id\":47,\"ref\":\"PKG-20260926-16E3-H\",\"name\":\"UNIT 2\"}],\"grand_total\":6000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:50:31'),
(70, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-0960 — 1 item(s), Total: ₱2,000.00', 11, 'package', NULL, '{\"package_reference\":\"PKG-20260926-0960\",\"items\":[{\"type\":\"house\",\"id\":48,\"ref\":\"PKG-20260926-0960-H\",\"name\":\"UNIT 3\"}],\"grand_total\":2000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:50:54'),
(71, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-6CA2 — 1 item(s), Total: ₱2,000.00', 12, 'package', NULL, '{\"package_reference\":\"PKG-20260926-6CA2\",\"items\":[{\"type\":\"house\",\"id\":49,\"ref\":\"PKG-20260926-6CA2-H\",\"name\":\"UNIT 3\"}],\"grand_total\":2000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 20:51:24'),
(72, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-F6B8 — 1 item(s), Total: ₱6,000.00', 13, 'package', NULL, '{\"package_reference\":\"PKG-20260926-F6B8\",\"items\":[{\"type\":\"house\",\"id\":50,\"ref\":\"PKG-20260926-F6B8-H\",\"name\":\"UNIT 2\"}],\"grand_total\":6000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 21:05:07'),
(73, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-52AE — 0 item(s), Total: ₱0.00', 14, 'package', NULL, '{\"package_reference\":\"PKG-20260926-52AE\",\"items\":[],\"grand_total\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 21:05:12'),
(74, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-1C4E — 0 item(s), Total: ₱0.00', 15, 'package', NULL, '{\"package_reference\":\"PKG-20260926-1C4E\",\"items\":[],\"grand_total\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 21:05:33'),
(75, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-8B41 — 1 item(s), Total: ₱2,000.00', 16, 'package', NULL, '{\"package_reference\":\"PKG-20260926-8B41\",\"items\":[{\"type\":\"house\",\"id\":51,\"ref\":\"PKG-20260926-8B41-H\",\"name\":\"UNIT 3\"}],\"grand_total\":2000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 21:05:54'),
(76, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-8B41\' (free cancellation)', 16, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 21:07:02'),
(77, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-8B41-H\' (free cancellation)', 51, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 21:07:05'),
(78, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-1C4E\' (free cancellation)', 15, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 21:07:10'),
(79, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-52AE\' (free cancellation)', 14, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 21:07:15'),
(80, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-F39F — 1 item(s), Total: ₱6,000.00', 17, 'package', NULL, '{\"package_reference\":\"PKG-20260926-F39F\",\"items\":[{\"type\":\"house\",\"id\":52,\"ref\":\"PKG-20260926-F39F-H\",\"name\":\"UNIT 2\"}],\"grand_total\":6000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 21:18:37'),
(81, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-F39F\' (free cancellation)', 17, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'warning', '2026-09-26 21:21:01'),
(82, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-F6B8\' (free cancellation)', 13, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:20'),
(83, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-6CA2\' (free cancellation)', 12, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:26'),
(84, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-0960\' (free cancellation)', 11, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:31'),
(85, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-16E3\' (free cancellation)', 10, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:35'),
(86, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-02DD\' (free cancellation)', 9, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:40'),
(87, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-7F11\' (free cancellation)', 8, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:45'),
(88, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-F8E2\' (free cancellation)', 7, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:50'),
(89, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-F39F-H\' (free cancellation)', 52, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:21:58'),
(90, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-F6B8-H\' (free cancellation)', 50, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:22:02'),
(91, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-6CA2-H\' (free cancellation)', 49, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:22:05'),
(92, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-0960-H\' (free cancellation)', 48, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 21:22:08'),
(93, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-16E3-H\' (free cancellation)', 47, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:21'),
(94, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-02DD-H\' (free cancellation)', 46, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:24'),
(95, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-7F11-H\' (free cancellation)', 45, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:27'),
(96, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-F8E2-H\' (free cancellation)', 44, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:31'),
(97, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'TOUR-20260926-6671\' (free cancellation)', 15, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:36'),
(98, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'PKG-20260926-9C97-T\' (free cancellation)', 14, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:40'),
(99, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'TOUR-20260924-4407\' (free cancellation)', 12, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:45'),
(100, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'TOUR-20260922-9010\' (free cancellation)', 10, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:49'),
(101, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'TOUR-20260922-2559\' (free cancellation)', 9, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:13:55'),
(102, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-8C83-F\' (free cancellation)', 11, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:00'),
(103, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-E2D4-F\' (free cancellation)', 10, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:09'),
(104, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-AAD9-F\' (free cancellation)', 9, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:14'),
(105, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-5FBC-F\' (free cancellation)', 8, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:20'),
(106, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'FOOD-20260926-7147\' (free cancellation)', 7, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:25'),
(107, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-BB5E-F\' (free cancellation)', 6, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:30'),
(108, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-9C97-F\' (free cancellation)', 5, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:38'),
(109, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'FOOD-20260922-1499\' (free cancellation)', 3, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-26 22:14:47'),
(110, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-5633 — 1 item(s), Total: ₱6,000.00', 18, 'package', NULL, '{\"package_reference\":\"PKG-20260926-5633\",\"items\":[{\"type\":\"house\",\"id\":53,\"ref\":\"PKG-20260926-5633-H\",\"name\":\"UNIT 2\"}],\"grand_total\":6000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 22:17:48'),
(111, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-AB28 — 2 item(s), Total: ₱10,000.00', 19, 'package', NULL, '{\"package_reference\":\"PKG-20260926-AB28\",\"items\":[{\"type\":\"house\",\"id\":54,\"ref\":\"PKG-20260926-AB28-H\",\"name\":\"UNIT 2\"},{\"type\":\"food\",\"id\":12,\"ref\":\"PKG-20260926-AB28-F\",\"name\":\"BOODLE FIGHT\"}],\"grand_total\":10000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 22:19:17'),
(112, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-173F — 0 item(s), Total: ₱0.00', 20, 'package', NULL, '{\"package_reference\":\"PKG-20260926-173F\",\"items\":[],\"grand_total\":0}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 22:46:10'),
(113, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-EB35 — 3 item(s), Total: ₱9,000.00', 21, 'package', NULL, '{\"package_reference\":\"PKG-20260926-EB35\",\"items\":[{\"type\":\"house\",\"id\":55,\"ref\":\"PKG-20260926-EB35-H\",\"name\":\"UNIT 3\"},{\"type\":\"food\",\"id\":13,\"ref\":\"PKG-20260926-EB35-F\",\"name\":\"BOODLE FIGHT\"},{\"type\":\"tour\",\"id\":16,\"ref\":\"PKG-20260926-EB35-T\",\"name\":\"Herein\"}],\"grand_total\":9000}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-26 22:47:07'),
(114, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-E5F7 — 0 item(s), Total: ₱0.00', 22, 'package', NULL, '{\"package_reference\":\"PKG-20260926-E5F7\",\"items\":[],\"grand_total\":0}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 23:17:47');
INSERT INTO `system_logs` (`id`, `user_id`, `username`, `fullname`, `role`, `action`, `module`, `description`, `target_id`, `target_type`, `old_values`, `new_values`, `ip_address`, `user_agent`, `status`, `created_at`) VALUES
(115, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260926-FB6D — 2 item(s), Total: ₱7,500.00', 23, 'package', NULL, '{\"package_reference\":\"PKG-20260926-FB6D\",\"items\":[{\"type\":\"house\",\"id\":56,\"ref\":\"PKG-20260926-FB6D-H\",\"name\":\"UNIT 2\"},{\"type\":\"food\",\"id\":14,\"ref\":\"PKG-20260926-FB6D-F\",\"name\":\"BILAO PACKAGES\"}],\"grand_total\":7500}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'success', '2026-09-26 23:19:56'),
(116, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-FB6D-H\' (free cancellation)', 56, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:28'),
(117, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-EB35-H\' (free cancellation)', 55, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:32'),
(118, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-AB28-H\' (free cancellation)', 54, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:35'),
(119, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid house booking \'PKG-20260926-5633-H\' (free cancellation)', 53, 'house_booking', '{\"old_status\":\"confirmed\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:39'),
(120, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid tour booking \'PKG-20260926-EB35-T\' (free cancellation)', 16, 'tour_booking', '{\"old_status\":\"\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:49'),
(121, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-FB6D-F\' (free cancellation)', 14, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:54'),
(122, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-EB35-F\' (free cancellation)', 13, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:28:59'),
(123, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid food booking \'PKG-20260926-AB28-F\' (free cancellation)', 12, 'food_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:04'),
(124, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-FB6D\' (free cancellation)', 23, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:09'),
(125, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-E5F7\' (free cancellation)', 22, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:15'),
(126, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-EB35\' (free cancellation)', 21, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:21'),
(127, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-173F\' (free cancellation)', 20, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:26'),
(128, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-AB28\' (free cancellation)', 19, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:33'),
(129, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260926-5633\' (free cancellation)', 18, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'warning', '2026-09-27 08:29:39'),
(130, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 09:16:37'),
(131, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260927-741F — 3 item(s), Total: ₱10,500.00', 24, 'package', NULL, '{\"package_reference\":\"PKG-20260927-741F\",\"items\":[{\"type\":\"house\",\"id\":57,\"ref\":\"PKG-20260927-741F-H\",\"name\":\"UNIT 2\"},{\"type\":\"food\",\"id\":15,\"ref\":\"PKG-20260927-741F-F\",\"name\":\"BILAO PACKAGES\"},{\"type\":\"tour\",\"id\":17,\"ref\":\"PKG-20260927-741F-T\",\"name\":\"Herein\"}],\"grand_total\":10500}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 09:18:46'),
(132, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for package booking PKG-20260927-741F — GCash Ref: 0045077587865', 24, 'package_booking', NULL, '{\"gcash_reference\":\"0045077587865\",\"filename\":\"1790473953_PKG-20260927-741F.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 09:52:33'),
(133, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260816-2228 — Reason: Booking dates no longer available', 16, 'house_booking', '{\"reference\":\"HS-20260816-2228\",\"amount\":\"11000.00\",\"payment_status\":\"paid\"}', '{\"reason\":\"Booking dates no longer available\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-27 14:03:15'),
(134, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260903-7958 — Reason: Guest requested cancellation', 18, 'house_booking', '{\"reference\":\"HS-20260903-7958\",\"amount\":\"11000.00\",\"payment_status\":\"paid\"}', '{\"reason\":\"Guest requested cancellation\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-27 14:12:39'),
(135, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260907-9985 — Reason: Guest requested cancellation', 23, 'house_booking', '{\"reference\":\"HS-20260907-9985\",\"amount\":\"5500.00\",\"payment_status\":\"pending\"}', '{\"reason\":\"Guest requested cancellation\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-27 14:14:07'),
(136, 1, 'admin', 'John Paul Navarro', 'admin', 'confirm_payment', 'booking', 'Confirmed house payment for booking ID 24', 24, 'booking', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 14:14:39'),
(137, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260831-7897 — Reason: Guest requested cancellation', 17, 'house_booking', '{\"reference\":\"HS-20260831-7897\",\"amount\":\"11000.00\",\"payment_status\":\"paid\"}', '{\"reason\":\"Guest requested cancellation\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-27 14:15:15'),
(138, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260908-2990 — Reason: Guest requested cancellation', 24, 'house_booking', '{\"reference\":\"HS-20260908-2990\",\"amount\":\"11000.00\",\"payment_status\":\"paid\"}', '{\"reason\":\"Guest requested cancellation\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-27 14:15:28'),
(139, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 15:48:01'),
(140, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260927-4CAA — 2 item(s), Total: ₱3,500.00', 25, 'package', NULL, '{\"package_reference\":\"PKG-20260927-4CAA\",\"items\":[{\"type\":\"house\",\"id\":58,\"ref\":\"PKG-20260927-4CAA-H\",\"name\":\"UNIT 3\"},{\"type\":\"food\",\"id\":16,\"ref\":\"PKG-20260927-4CAA-F\",\"name\":\"BILAO PACKAGES\"}],\"grand_total\":3500}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 15:48:52'),
(141, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for package booking PKG-20260927-4CAA — GCash Ref: 0045077587865', 25, 'package_booking', NULL, '{\"gcash_reference\":\"0045077587865\",\"filename\":\"1790495365_PKG-20260927-4CAA.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 15:49:34'),
(142, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 19:58:26'),
(143, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:01:03'),
(144, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":2,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:01:14'),
(145, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'Rinn\' logged in from NEW device — OTP sent', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:03:54'),
(146, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'verify_device', 'auth', 'User \'Rinn\' successfully verified a NEW device and logged in — IP: ::1', 9, 'user', NULL, '{\"role\":\"guest\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36 Edg\\/154.0.0.0\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-27 14:06:36\",\"redirect_to\":\"index.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:06:36'),
(147, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:08:51'),
(148, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:09:02'),
(149, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:09:12'),
(150, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":2,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:09:16'),
(151, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:13:22'),
(152, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:13:27'),
(153, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'RInn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:13:48'),
(154, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'RInn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:13:55'),
(155, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'RInn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"attempts_left\":2,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:13:57'),
(156, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'RInn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"attempts_left\":1,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:13:59'),
(157, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Login locked out after too many failed attempts for \'RInn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"reason\":\"lockout_triggered\",\"remaining\":300}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:14:01'),
(158, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Login blocked — account locked for username \'RInn\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"reason\":\"lockout\",\"remaining\":288}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:14:13'),
(159, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Jjohn\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"Jjohn\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:14:34'),
(160, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Jjohn\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"Jjohn\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:27:22'),
(161, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Rinn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"Rinn\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:27:29'),
(162, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Rinn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"Rinn\",\"ip\":\"::1\",\"attempts_left\":2,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:27:32'),
(163, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Rinn\' (IP: ::1)', 9, 'user', NULL, '{\"username\":\"Rinn\",\"ip\":\"::1\",\"attempts_left\":1,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:27:36'),
(164, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Login locked out after too many failed attempts for IP \'::1\' (username attempted: \'aksdjpRinn\')', NULL, 'user', NULL, '{\"username\":\"aksdjpRinn\",\"ip\":\"::1\",\"reason\":\"lockout_triggered\",\"remaining\":300}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:27:38'),
(165, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Login blocked — device locked for IP \'::1\' (username attempted: \'john\')', NULL, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"reason\":\"lockout\",\"remaining\":287}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:27:51'),
(166, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Login blocked — device locked for IP \'::1\' (username attempted: \'RInn\')', NULL, 'user', NULL, '{\"username\":\"RInn\",\"ip\":\"::1\",\"reason\":\"lockout\",\"remaining\":2040}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:32:04'),
(167, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:33:09'),
(168, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:33:49'),
(169, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'john\' (IP: ::1)', 8, 'user', NULL, '{\"username\":\"john\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-09-27 20:33:58'),
(170, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:34:09'),
(171, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 20:34:17'),
(172, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 21:06:47'),
(173, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 21:09:18'),
(174, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'Guest updated overall feedback — Rating: 3/5', 5, 'overall_feedback', NULL, '{\"rating\":3,\"anonymous\":0,\"comment_length\":7}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 21:33:42'),
(175, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'review', 'Guest updated overall feedback — Rating: 4/5', 5, 'overall_feedback', NULL, '{\"rating\":4,\"anonymous\":0,\"comment_length\":7}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-27 21:33:48'),
(176, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 22:04:43'),
(177, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 22:04:56'),
(178, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/153.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-27 16:05:15\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-27 22:05:15'),
(179, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-28 00:04:56'),
(180, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'johnpaul\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"johnpaul\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'failed', '2026-09-28 00:05:08'),
(181, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'johnpaul\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"johnpaul\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'failed', '2026-09-28 00:05:16'),
(182, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-28 08:25:25'),
(183, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-28 08:25:47'),
(184, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-28 08:25:57'),
(185, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36 Edg\\/154.0.0.0\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-28 02:26:26\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-28 08:26:26'),
(186, NULL, NULL, NULL, NULL, 'register', 'auth', 'Bagong user na nagrehistro: Johnpaul (fujiwarachika034@gmail.com)', 12, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-28 08:30:50'),
(187, 1, 'admin', 'John Paul Navarro', 'admin', 'delete', 'user', 'Deleted user: Johnpaul (guest) — Reason: Violation of Terms of Service', 12, 'user', '{\"username\":\"Johnpaul\",\"email\":\"fujiwarachika034@gmail.com\",\"role\":\"guest\"}', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-28 08:31:07'),
(188, NULL, NULL, NULL, NULL, 'register', 'auth', 'New user registered: johnpaul (fujiwarachika034@gmail.com)', 13, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-28 08:35:16'),
(189, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-28 16:00:21'),
(190, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'Rinn\' logged in from NEW device — OTP sent', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-28 18:13:53'),
(191, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'verify_device', 'auth', 'User \'Rinn\' successfully verified a NEW device and logged in — IP: ::1', 9, 'user', NULL, '{\"role\":\"guest\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36 Edg\\/154.0.0.0\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-28 12:14:35\",\"redirect_to\":\"index.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-28 18:14:35'),
(192, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest created PACKAGE booking PKG-20260928-F864 — 3 item(s), Total: ₱15,500.00', 26, 'package', NULL, '{\"package_reference\":\"PKG-20260928-F864\",\"items\":[{\"type\":\"house\",\"id\":59,\"ref\":\"PKG-20260928-F864-H\",\"name\":\"UNIT 1\"},{\"type\":\"food\",\"id\":17,\"ref\":\"PKG-20260928-F864-F\",\"name\":\"BILAO PACKAGES\"},{\"type\":\"tour\",\"id\":18,\"ref\":\"PKG-20260928-F864-T\",\"name\":\"Herein\"}],\"grand_total\":15500}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-29 05:47:13'),
(193, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for package booking PKG-20260928-F864 — GCash Ref: 23423324234234234234', 26, 'package_booking', NULL, '{\"gcash_reference\":\"23423324234234234234\",\"filename\":\"1790632071_PKG-20260928-F864.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-29 05:48:01'),
(194, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest cancelled unpaid package booking \'PKG-20260927-741F\' (free cancellation)', 24, 'package_booking', '{\"old_status\":\"pending\",\"payment_status\":\"pending\"}', '{\"new_status\":\"cancelled\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'warning', '2026-09-29 05:48:22'),
(195, 1, 'admin', 'John Paul Navarro', 'admin', 'confirm_payment', 'booking', 'Confirmed package payment for booking ID 26', 26, 'booking', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-29 05:59:48'),
(196, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'unknown', 'Multi-blocked house #4 — 3 dates blocked, 0 skipped', 4, 'blocked_dates', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'success', '2026-09-29 06:21:32'),
(197, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login', 'auth', 'User \'Rinn\' logged in (trusted device)', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-29 10:30:43'),
(198, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260923-8791 — Reason: Guest requested cancellation', 35, 'house_booking', '{\"reference\":\"HS-20260923-8791\",\"amount\":\"12000.00\",\"payment_status\":\"pending\"}', '{\"reason\":\"Guest requested cancellation\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'warning', '2026-09-29 14:27:58'),
(199, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for house booking HS-20260923-8791 — GCash Ref: 23423324234234234234', 35, 'house_booking', NULL, '{\"gcash_reference\":\"23423324234234234234\",\"filename\":\"1790671505_HS-20260923-8791.png\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-09-29 16:45:18'),
(200, 1, 'admin', 'John Paul Navarro', 'admin', 'update', 'activity', 'Updated activity: Jet Ski (changed: featured)', 3, 'activity', '{\"name\":\"Jet Ski\",\"category\":\"Water Sports\",\"price\":\"1500.00\",\"status\":\"available\"}', '{\"name\":\"Jet Ski\",\"category\":\"Water Sports\",\"price\":\"1500.00\",\"status\":\"available\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-09-29 19:53:58'),
(201, 1, 'admin', 'John Paul Navarro', 'admin', 'update', 'activity', 'Updated activity: Snorkeling (changed: featured)', 5, 'activity', '{\"name\":\"Snorkeling\",\"category\":\"Water Activities\",\"price\":\"200.00\",\"status\":\"available\"}', '{\"name\":\"Snorkeling\",\"category\":\"Water Activities\",\"price\":\"200.00\",\"status\":\"available\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-09-29 19:54:08'),
(202, 1, 'admin', 'John Paul Navarro', 'admin', 'update', 'activity', 'Updated activity: Hurricane (changed: featured)', 2, 'activity', '{\"name\":\"Hurricane\",\"category\":\"Water Activities\",\"price\":\"1800.00\",\"status\":\"available\"}', '{\"name\":\"Hurricane\",\"category\":\"Water Activities\",\"price\":\"1800.00\",\"status\":\"available\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-09-29 19:54:19'),
(203, 1, 'admin', 'John Paul Navarro', 'admin', 'update', 'activity', 'Updated activity: Helmet Diving (changed: featured)', 6, 'activity', '{\"name\":\"Helmet Diving\",\"category\":\"Water Activities\",\"price\":\"400.00\",\"status\":\"available\"}', '{\"name\":\"Helmet Diving\",\"category\":\"Water Activities\",\"price\":\"400.00\",\"status\":\"available\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-09-29 19:54:28'),
(204, 1, 'admin', 'John Paul Navarro', 'admin', 'unknown', 'booking', 'Admin cancelled house booking HS-20260915-9520 — Reason: Guest requested cancellation', 32, 'house_booking', '{\"reference\":\"HS-20260915-9520\",\"amount\":\"6000.00\",\"payment_status\":\"paid\"}', '{\"reason\":\"Guest requested cancellation\",\"cancelled_by\":\"admin\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'warning', '2026-09-29 23:01:21'),
(205, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-09-30 11:54:05'),
(206, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-10-30 05:55:45\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-09-30 11:55:45'),
(207, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 10:43:19'),
(208, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 10:43:31'),
(209, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'staff\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"staff\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 10:43:46'),
(210, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'staff\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"staff\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 10:43:46'),
(211, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'staff\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"staff\",\"ip\":\"::1\",\"attempts_left\":2,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 10:43:52'),
(212, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 11:03:02'),
(213, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 11:03:25'),
(214, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 11:05:05'),
(215, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 11:06:08'),
(216, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Staff\' (IP: ::1)', NULL, 'user', NULL, '{\"username\":\"Staff\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"user_not_found\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 11:06:14'),
(217, 11, 'paul', 'john paul navarro', 'staff', 'login_failed', 'auth', 'Failed to send device OTP to \'paul\'', 11, 'user', NULL, '{\"email\":\"johnpaulnavarro146@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 11:06:24'),
(218, 11, 'paul', 'john paul navarro', 'staff', 'login_failed', 'auth', 'Failed to send device OTP to \'paul\'', 11, 'user', NULL, '{\"email\":\"johnpaulnavarro146@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 11:34:41'),
(219, 11, 'paul', 'john paul navarro', 'staff', 'login_failed', 'auth', 'Failed to send device OTP to \'paul\'', 11, 'user', NULL, '{\"email\":\"johnpaulnavarro146@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 11:34:44'),
(220, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-01 12:09:56'),
(221, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'success', '2026-10-01 14:24:29'),
(222, 1, 'admin', 'John Paul Navarro', 'admin', 'login_failed', 'auth', 'Failed to send device OTP to \'admin\'', 1, 'user', NULL, '{\"email\":\"johnpaulnavarro0105@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-01 15:19:57'),
(223, 1, 'admin', 'John Paul Navarro', 'admin', 'login_failed', 'auth', 'Failed to send device OTP to \'admin\'', 1, 'user', NULL, '{\"email\":\"johnpaulnavarro0105@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'failed', '2026-10-01 15:20:10'),
(224, 11, 'paul', 'john paul navarro', 'staff', 'login_failed', 'auth', 'Failed to send device OTP to \'paul\'', 11, 'user', NULL, '{\"email\":\"johnpaulnavarro146@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'failed', '2026-10-01 15:20:32'),
(225, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'login_failed', 'auth', 'Failed to send device OTP to \'Rinn\'', 9, 'user', NULL, '{\"email\":\"kanonshibuya33@gmail.com\",\"ip\":\"::1\",\"reason\":\"otp_email_failed\"}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'failed', '2026-10-01 15:20:50'),
(226, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 00:51:38'),
(227, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'Rinn\' logged in from NEW device — OTP sent', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 12:52:47'),
(228, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'verify_device', 'auth', 'User \'Rinn\' successfully verified a NEW device and logged in — IP: ::1', 9, 'user', NULL, '{\"role\":\"guest\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-11-01 06:56:26\",\"redirect_to\":\"index.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 12:56:27'),
(229, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'update', 'booking', 'Guest removed house from package cart', NULL, 'package', NULL, '{\"removed_item\":\"house\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'warning', '2026-10-02 12:57:14'),
(230, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest booked house \'UNIT 1\' (Ref: HS-20261002-2181) — ₱11,000.00', 60, 'house_booking', NULL, '{\"house_id\":\"4\",\"house_name\":\"UNIT 1\",\"check_in\":\"2026-10-28\",\"check_in_time\":\"14:00\",\"check_out\":\"2026-10-30\",\"check_out_time\":\"12:00\",\"guests\":1,\"nights\":2,\"total\":11000,\"reference\":\"HS-20261002-2181\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 12:57:42'),
(231, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'view', 'review', 'User \'Rinn\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":true,\"role\":\"guest\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 15:12:07'),
(232, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 15:54:17'),
(233, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 15:55:05'),
(234, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 15:55:12'),
(235, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-11-01 09:56:55\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 15:56:55');
INSERT INTO `system_logs` (`id`, `user_id`, `username`, `fullname`, `role`, `action`, `module`, `description`, `target_id`, `target_type`, `old_values`, `new_values`, `ip_address`, `user_agent`, `status`, `created_at`) VALUES
(236, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-02 19:22:22'),
(237, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:17:46'),
(238, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:32:11'),
(239, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:32:45'),
(240, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'Rinn\' logged in from NEW device — OTP sent', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:35:44'),
(241, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'verify_device', 'auth', 'User \'Rinn\' successfully verified a NEW device and logged in — IP: ::1', 9, 'user', NULL, '{\"role\":\"guest\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-11-02 15:37:35\",\"redirect_to\":\"index.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:37:35'),
(242, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'logout', 'auth', 'User \'Rinn\' logged out', 9, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:38:18'),
(243, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:41:45'),
(244, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-11-02 15:42:30\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-03 21:42:30'),
(245, 1, 'admin', 'John Paul Navarro', 'admin', 'upload', 'content', 'Admin \'admin\' uploaded a new Houses page hero image', NULL, 'site_content', NULL, '{\"file\":\"houses-hero.jpg\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 00:09:59'),
(246, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 01:16:16'),
(247, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'paul\' logged in from NEW device — OTP sent', 11, 'user', NULL, '{\"role\":\"staff\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 01:16:45'),
(248, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 01:20:32'),
(249, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 01:35:40'),
(250, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'paul\' logged in from NEW device — OTP sent', 11, 'user', NULL, '{\"role\":\"staff\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 20:49:07'),
(251, 11, 'paul', 'john paul navarro', 'staff', 'verify_device', 'auth', 'User \'paul\' successfully verified a NEW device and logged in — IP: ::1', 11, 'user', NULL, '{\"role\":\"staff\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-11-03 14:51:08\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 20:51:08'),
(252, 11, 'paul', 'john paul navarro', 'staff', 'logout', 'auth', 'User \'paul\' logged out', 11, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 21:00:13'),
(253, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":false,\"pending_otp\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 21:00:31'),
(254, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: ::1', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"::1\",\"device_expires_at\":\"2026-11-03 15:01:31\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-04 21:01:31'),
(255, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'admin\' (IP: ::1)', 1, 'user', NULL, '{\"username\":\"admin\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-05 23:39:07'),
(256, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'admin\' (IP: ::1)', 1, 'user', NULL, '{\"username\":\"admin\",\"ip\":\"::1\",\"attempts_left\":3,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'failed', '2026-10-05 23:39:12'),
(257, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-05 23:39:18'),
(258, 1, 'admin', 'John Paul Navarro', 'admin', 'delete', 'content', 'Admin \'admin\' removed the Houses page hero image', NULL, 'site_content', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'warning', '2026-10-06 00:28:04'),
(259, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'admin\' (IP: ::1)', 1, 'user', NULL, '{\"username\":\"admin\",\"ip\":\"::1\",\"attempts_left\":4,\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'failed', '2026-10-06 14:01:41'),
(260, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'success', '2026-10-06 14:01:56'),
(261, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 14:16:38'),
(262, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"::1\",\"device_trusted\":true}', '::1', 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36', 'success', '2026-10-06 14:18:14'),
(263, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 14:58:42'),
(264, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 14:58:47'),
(265, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '158.69.55.148', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'success', '2026-10-06 09:41:28'),
(266, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 09:43:21'),
(267, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Yoanna\' (IP: 49.151.133.253)', 7, 'user', NULL, '{\"username\":\"Yoanna\",\"ip\":\"49.151.133.253\",\"attempts_left\":5,\"reason\":\"wrong_password\"}', '49.151.133.253', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_7_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/23H30 [FBAN/FBIOS;FBAV/580.0.0.29.107;FBBV/1073972887;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/26.7.1;FBSS/3;FBCR/;FBID/phone;FBLC/en_PH;FBOP/80]', 'failed', '2026-10-06 09:43:40'),
(268, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'hahaha\' (IP: 49.151.133.253)', NULL, 'user', NULL, '{\"username\":\"hahaha\",\"ip\":\"49.151.133.253\",\"attempts_left\":5,\"reason\":\"user_not_found\"}', '49.151.133.253', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_7_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/23H30 [FBAN/FBIOS;FBAV/580.0.0.29.107;FBBV/1073972887;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/26.7.1;FBSS/3;FBCR/;FBID/phone;FBLC/en_PH;FBOP/80]', 'failed', '2026-10-06 09:43:48'),
(269, NULL, NULL, NULL, NULL, 'login_failed', 'auth', 'Failed login attempt for username \'Yoanna\' (IP: 49.151.133.253)', 7, 'user', NULL, '{\"username\":\"Yoanna\",\"ip\":\"49.151.133.253\",\"attempts_left\":5,\"reason\":\"wrong_password\"}', '49.151.133.253', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_7_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/23H30 [FBAN/FBIOS;FBAV/580.0.0.29.107;FBBV/1073972887;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/26.7.1;FBSS/3;FBCR/;FBID/phone;FBLC/en_PH;FBOP/80]', 'failed', '2026-10-06 09:43:57'),
(270, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"175.176.15.175\",\"device_trusted\":false,\"pending_otp\":true}', '175.176.15.175', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 09:55:07'),
(271, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: 175.176.15.175', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"175.176.15.175\",\"device_expires_at\":\"2026-11-05 13:56:47\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '175.176.15.175', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 09:56:47'),
(272, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '173.46.91.84', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'success', '2026-10-06 10:51:54'),
(273, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '158.69.117.45', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'success', '2026-10-06 10:53:51'),
(274, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"175.176.15.175\",\"device_trusted\":true}', '175.176.15.175', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 11:03:03'),
(275, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'admin\' logged in from NEW device — OTP sent', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"182.255.40.189\",\"device_trusted\":false,\"pending_otp\":true}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 11:05:16'),
(276, 1, 'admin', 'John Paul Navarro', 'admin', 'verify_device', 'auth', 'User \'admin\' successfully verified a NEW device and logged in — IP: 182.255.40.189', 1, 'user', NULL, '{\"role\":\"admin\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"182.255.40.189\",\"device_expires_at\":\"2026-11-05 15:05:33\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 11:05:33'),
(277, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '173.46.91.145', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'success', '2026-10-06 11:31:20'),
(278, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 11:52:35'),
(279, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'Rinn\' logged in from NEW device — OTP sent', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"182.255.40.189\",\"device_trusted\":false,\"pending_otp\":true}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-10-06 11:52:58'),
(280, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'paul\' logged in from NEW device — OTP sent', 11, 'user', NULL, '{\"role\":\"staff\",\"ip\":\"182.255.40.189\",\"device_trusted\":false,\"pending_otp\":true}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 11:58:53'),
(281, 11, 'paul', 'john paul navarro', 'staff', 'verify_device', 'auth', 'User \'paul\' successfully verified a NEW device and logged in — IP: 182.255.40.189', 11, 'user', NULL, '{\"role\":\"staff\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36\",\"ip\":\"182.255.40.189\",\"device_expires_at\":\"2026-11-05 15:59:34\",\"redirect_to\":\"admin-dashboard.php\",\"page\":\"verify-device.php\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-06 11:59:34'),
(282, NULL, NULL, NULL, NULL, 'login', 'auth', 'User \'Rinn\' logged in from NEW device — OTP sent', 9, 'user', NULL, '{\"role\":\"guest\",\"ip\":\"182.255.40.189\",\"device_trusted\":false,\"pending_otp\":true}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-10-06 12:04:21'),
(283, NULL, NULL, NULL, NULL, 'unknown', 'auth', 'New device verification FAILED — incorrect OTP (user ID: 9) — IP: 182.255.40.189', 9, 'user', NULL, '{\"reason\":\"incorrect_otp\",\"ip\":\"182.255.40.189\",\"page\":\"verify-device.php\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-10-06 12:04:42'),
(284, NULL, NULL, NULL, NULL, 'unknown', 'auth', 'New device verification FAILED — incorrect OTP (user ID: 9) — IP: 182.255.40.189', 9, 'user', NULL, '{\"reason\":\"incorrect_otp\",\"ip\":\"182.255.40.189\",\"page\":\"verify-device.php\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'failed', '2026-10-06 12:06:23'),
(285, NULL, NULL, NULL, NULL, 'unknown', 'auth', 'User \'Rinn\' requested a NEW device-verification OTP (resend) — IP: 182.255.40.189', 9, 'user', NULL, '{\"email\":\"kanonshibuya33@gmail.com\",\"ip\":\"182.255.40.189\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36 Edg\\/154.0.0.0\",\"page\":\"verify-device.php\",\"purpose\":\"new_device_verification\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-10-06 12:06:28'),
(286, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'verify_device', 'auth', 'User \'Rinn\' successfully verified a NEW device and logged in — IP: 182.255.40.189', 9, 'user', NULL, '{\"role\":\"guest\",\"device_name\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/154.0.0.0 Safari\\/537.36 Edg\\/154.0.0.0\",\"ip\":\"182.255.40.189\",\"device_expires_at\":\"2026-11-05 16:06:43\",\"redirect_to\":\"index.php\",\"page\":\"verify-device.php\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-10-06 12:06:43'),
(287, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'create', 'booking', 'Guest booked house \'UNIT 1\' (Ref: HS-20261006-1908) — ₱11,000.00', 61, 'house_booking', NULL, '{\"house_id\":\"4\",\"house_name\":\"UNIT 1\",\"check_in\":\"2026-10-22\",\"check_in_time\":\"05:00\",\"check_out\":\"2026-10-24\",\"check_out_time\":\"12:00\",\"guests\":1,\"nights\":2,\"total\":11000,\"reference\":\"HS-20261006-1908\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-10-06 12:13:52'),
(288, 9, 'Rinn', 'Rinn O Yoshida', 'guest', 'upload_proof', 'booking', 'Guest uploaded payment proof for house booking HS-20261006-1908 — GCash Ref: 23423324234234234234', 61, 'house_booking', NULL, '{\"gcash_reference\":\"23423324234234234234\",\"filename\":\"1791303272_HS-20261006-1908.png\"}', '182.255.40.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'success', '2026-10-06 12:14:37'),
(289, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '139.99.237.180', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.246', 'success', '2026-10-06 21:47:31'),
(290, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '139.99.237.180', 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.83 Safari/537.1', 'success', '2026-10-06 22:12:39'),
(291, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '139.99.237.180', 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.83 Safari/537.1', 'success', '2026-10-06 22:12:45'),
(292, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '35.252.229.76', 'Mozilla/5.0 (Linux; Android 12; Pixel 6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36', 'success', '2026-10-06 22:59:04'),
(293, 1, 'admin', 'John Paul Navarro', 'admin', 'login', 'auth', 'User \'admin\' logged in (trusted device)', 1, 'user', NULL, '{\"role\":\"admin\",\"ip\":\"175.176.15.175\",\"device_trusted\":true}', '175.176.15.175', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-07 12:24:08'),
(294, 1, 'admin', 'John Paul Navarro', 'admin', 'logout', 'auth', 'User \'admin\' logged out', 1, 'user', NULL, NULL, '175.176.15.175', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'success', '2026-10-07 12:40:52'),
(295, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '45.138.12.168', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36', 'success', '2026-10-07 13:57:26'),
(296, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '43.130.72.177', 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1', 'success', '2026-10-07 15:58:05'),
(297, NULL, NULL, NULL, NULL, 'view', 'review', 'User \'Guest\' viewed the public Reviews page (2 reviews shown)', NULL, 'page', NULL, '{\"page\":\"reviews.php\",\"total_reviews\":2,\"avg_rating\":4,\"is_logged_in\":false,\"role\":\"guest\"}', '170.106.148.137', 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1', 'success', '2026-10-07 19:45:52');

-- --------------------------------------------------------

--
-- Table structure for table `tours`
--

CREATE TABLE `tours` (
  `id` int(11) NOT NULL,
  `tour_name` varchar(200) NOT NULL,
  `tour_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `places_to_visit` text DEFAULT NULL,
  `duration_hours` int(11) NOT NULL,
  `max_guests` int(11) NOT NULL,
  `price_per_boat` decimal(10,2) NOT NULL DEFAULT 0.00,
  `boat_capacity` int(11) NOT NULL DEFAULT 5,
  `inclusions` text DEFAULT NULL,
  `status` enum('available','fully_booked','seasonal') DEFAULT 'available',
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `boat_type` varchar(50) DEFAULT 'Small',
  `folder_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tours`
--

INSERT INTO `tours` (`id`, `tour_name`, `tour_type`, `description`, `places_to_visit`, `duration_hours`, `max_guests`, `price_per_boat`, `boat_capacity`, `inclusions`, `status`, `image`, `created_at`, `boat_type`, `folder_name`) VALUES
(5, 'Herein', '', 'The new and improved Deluxe Boat herein Hundred Islands which can accommodate 16-20 pax.', NULL, 0, 20, 3000.00, 20, NULL, 'available', '1786672170_Herein.jpg', '2026-08-14 01:49:30', 'Small', 'Herein'),
(6, 'Mary Anne ', '', ' Deluxe Boat herein Hundred Islands which can accommodate 16-20 pax.', NULL, 0, 20, 4000.00, 20, NULL, 'available', '1789129315_Mary_Anne_.jfif', '2026-09-11 12:21:55', 'Small', 'Mary_Anne'),
(7, 'Dane Madhizon L.284', '', ' Deluxe Boat herein Hundred Islands which can accommodate 16-20 pax.', NULL, 0, 15, 2880.00, 15, NULL, 'available', '1789129550_Dane_Madhizon_L.284.jfif', '2026-09-11 12:25:50', 'Small', 'Dane_Madhizon_L.284');

-- --------------------------------------------------------

--
-- Table structure for table `tour_activities`
--

CREATE TABLE `tour_activities` (
  `id` int(11) NOT NULL,
  `tour_id` int(11) NOT NULL,
  `activity_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tour_bookings`
--

CREATE TABLE `tour_bookings` (
  `id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `tour_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `booking_date` date NOT NULL,
  `number_of_guests` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `reservation_fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `special_requests` text DEFAULT NULL,
  `payment_status` enum('pending','reservation_paid','paid','cancelled') NOT NULL DEFAULT 'pending',
  `booking_status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_proof` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `reservation_paid_at` datetime DEFAULT NULL,
  `balance_paid_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `reject_notes` text DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `feedback_is_anonymous` tinyint(1) DEFAULT 0,
  `guest_name` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `gcash_reference` varchar(30) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `package_reference` varchar(50) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `rebook_count` int(11) NOT NULL DEFAULT 0,
  `rebooked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tour_bookings`
--

INSERT INTO `tour_bookings` (`id`, `guest_id`, `tour_id`, `reference_number`, `booking_date`, `number_of_guests`, `total_amount`, `reservation_fee_amount`, `amount_paid`, `special_requests`, `payment_status`, `booking_status`, `payment_proof`, `proof_uploaded_at`, `paid_at`, `reservation_paid_at`, `balance_paid_at`, `reject_reason`, `reject_notes`, `rejected_by`, `rejected_at`, `created_at`, `feedback_is_anonymous`, `guest_name`, `contact_number`, `preferred_time`, `gcash_reference`, `cancelled_at`, `cancellation_reason`, `package_reference`, `package_id`, `completed_at`, `rebook_count`, `rebooked_at`) VALUES
(1, 4, 5, 'TOUR-20260910-1001', '2026-09-25', 5, 3000.00, 1000.00, 0.00, '', 'pending', 'completed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-10 06:14:49', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 17:00:00', 0, NULL),
(2, 4, 5, 'TOUR-20260910-8996', '2026-09-25', 5, 3000.00, 1000.00, 1000.00, '', 'reservation_paid', 'completed', NULL, NULL, '2026-09-10 08:54:59', '2026-09-10 08:54:59', NULL, NULL, NULL, NULL, NULL, '2026-09-10 06:14:57', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 17:00:00', 0, NULL),
(3, 4, 5, 'TOUR-20260910-8539', '2026-09-18', 8, 3000.00, 1000.00, 0.00, 'ahhhhhhhhhh', 'pending', 'completed', '1789055448_TOUR-20260910-8539.png', '2026-09-10 10:50:48', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-10 15:47:27', 0, 'john paul navarro', '09505241711', '07:00:00', NULL, NULL, NULL, NULL, NULL, '2026-09-18 07:00:00', 0, NULL),
(4, 4, 6, 'TOUR-20260911-5949', '2026-09-26', 16, 4000.00, 1000.00, 1000.00, 'jason\r\nfaye\r\ngian\r\nandrew\r\njhoncel\r\naugust\r\ngids\r\nrainier\r\nrivi\r\nangel\r\nruss\r\njolina\r\nteddy\r\ntom\r\ncj\r\ncedie\r\ndave\r\ncy', 'reservation_paid', 'completed', '1789138132_TOUR-20260911-5949.png', '2026-09-11 09:48:52', '2026-09-11 09:57:25', '2026-09-11 09:57:25', NULL, NULL, NULL, NULL, NULL, '2026-09-11 14:48:13', 0, 'John Paul Onia Navarro', '09505241711', '08:00:00', NULL, NULL, NULL, NULL, NULL, '2026-09-26 08:00:00', 0, NULL),
(5, 4, 7, 'TOUR-20260916-7043', '2026-09-24', 10, 2880.00, 1000.00, 0.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'pending', 'completed', '1789535461_TOUR-20260916-7043.png', '2026-09-16 13:11:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:10:22', 0, 'John Paul Onia Navarro', '09505241711', '07:00:00', '0045077587865', NULL, NULL, NULL, NULL, '2026-09-24 07:00:00', 0, NULL),
(6, 4, 6, 'TOUR-20260916-1549', '2026-09-22', 10, 4000.00, 1000.00, 1000.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'reservation_paid', 'completed', '1789535837_TOUR-20260916-1549.png', '2026-09-16 13:17:17', '2026-09-16 13:21:07', '2026-09-16 13:21:07', NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:15:49', 0, 'john paul navarro', '09505241711', '07:00:00', '0045077587865', NULL, NULL, NULL, NULL, '2026-09-22 07:00:00', 0, NULL),
(7, 4, 6, 'TOUR-20260916-4897', '2026-09-17', 10, 4000.00, 1000.00, 0.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'pending', 'completed', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:31:09', 0, 'trimuru tempest', '09505241711', '07:00:00', NULL, NULL, NULL, NULL, NULL, '2026-09-17 07:00:00', 0, NULL),
(8, 4, 6, 'TOUR-20260916-3670', '2026-09-23', 10, 4000.00, 1000.00, 0.00, '\r\ngian\r\nfaye\r\nentoy\r\njhoncel\r\npatrick\r\nyoanna\r\nandrew\r\nmia\r\ngids\r\nian', 'pending', 'completed', '1789613859_TOUR-20260916-3670.png', '2026-09-17 10:57:39', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-16 05:35:40', 0, 'trimuru tempest', '09505241711', '08:00:00', '0045077587865', NULL, NULL, NULL, NULL, '2026-09-23 08:00:00', 0, NULL),
(9, 5, 6, 'TOUR-20260922-2559', '2026-09-25', 5, 4000.00, 1000.00, 0.00, 'bakit', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22 08:07:59', 0, 'John Paul Onia Navarro', '09123456678', '07:00:00', NULL, '2026-09-26 22:13:55', NULL, NULL, NULL, NULL, 0, NULL),
(10, 5, 7, 'TOUR-20260922-9010', '2026-09-25', 2, 2880.00, 1000.00, 0.00, 'wal;a', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22 08:08:56', 0, 'John Paul Onia Navarro', '09123456678', '08:00:00', NULL, '2026-09-26 22:13:49', NULL, NULL, NULL, NULL, 0, NULL),
(11, 5, 6, 'TOUR-20260924-9652', '2026-10-15', 5, 4000.00, 1000.00, 0.00, 'zsdfd', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-24 10:45:27', 0, 'trimuru tempest', '+63123456789', '06:00:00', NULL, '2026-09-24 20:22:24', NULL, NULL, NULL, NULL, 0, NULL),
(12, 5, 6, 'TOUR-20260924-4407', '2026-10-16', 6, 4000.00, 1000.00, 0.00, 'dfzfd', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-24 10:58:59', 0, 'trimuru tempest', '+639123456678', '06:00:00', NULL, '2026-09-26 22:13:45', NULL, NULL, NULL, NULL, 0, NULL),
(14, 5, 5, 'PKG-20260926-9C97-T', '2026-10-16', 10, 3000.00, 0.00, 0.00, 'safe ride', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 11:54:33', 0, 'Rinn O Yoshida', '+639505241711', '08:00:00', NULL, '2026-09-26 22:13:40', NULL, NULL, 1, NULL, 0, NULL),
(15, 5, 6, 'TOUR-20260926-6671', '2026-10-21', 5, 4000.00, 1000.00, 0.00, 'sxfthdty', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 12:04:25', 0, 'Rinn O Yoshida', '+639505241711', '09:00:00', NULL, '2026-09-26 22:13:36', NULL, NULL, NULL, NULL, 0, NULL),
(16, 5, 5, 'PKG-20260926-EB35-T', '2026-09-30', 4, 3000.00, 0.00, 0.00, 'asdasda', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-26 14:47:07', 0, 'Rinn O Yoshida', '+639505241711', '07:00:00', NULL, '2026-09-27 08:28:49', NULL, NULL, 21, NULL, 0, NULL),
(17, 5, 5, 'PKG-20260927-741F-T', '2026-09-30', 2, 3000.00, 0.00, 0.00, '', 'pending', 'cancelled', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27 01:18:46', 0, 'Rinn O Yoshida', '+639505241711', '09:00:00', NULL, '2026-09-29 05:48:18', NULL, NULL, 24, NULL, 0, NULL),
(18, 5, 5, 'PKG-20260928-F864-T', '2026-10-28', 8, 3000.00, 0.00, 0.00, 'wala', 'reservation_paid', 'confirmed', NULL, NULL, '2026-09-29 05:59:43', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-28 21:47:13', 0, 'Rinn yoshida', '+639505241711', '08:00:00', NULL, NULL, NULL, NULL, 26, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tour_gallery`
--

CREATE TABLE `tour_gallery` (
  `id` int(11) NOT NULL,
  `tour_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `is_main` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tour_islands`
--

CREATE TABLE `tour_islands` (
  `id` int(11) NOT NULL,
  `tour_id` int(11) NOT NULL,
  `island_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `stop_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trusted_devices`
--

CREATE TABLE `trusted_devices` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `device_token` varchar(64) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trusted_devices`
--

INSERT INTO `trusted_devices` (`id`, `user_id`, `device_token`, `device_name`, `user_agent`, `ip_address`, `last_used_at`, `expires_at`, `created_at`) VALUES
(1, 8, '20ea8e9fb4c556fd3916637b4824bb1d5826f6699611efd93668dabd496a3f51', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '::1', '2026-09-16 12:13:13', '2026-10-16 06:13:13', '2026-09-16 12:13:13'),
(2, 1, '8f88ac8c8ac456daefeba587ac41afacb38ad0bd1753926578d78a6e919e419a', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '::1', '2026-09-16 13:20:54', '2026-10-16 07:20:54', '2026-09-16 13:20:54'),
(3, 8, 'f2b59722bec5bff0f02fbc5c2ad61194f13b371580c22d766f428d8d4c73a1d4', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '::1', '2026-09-17 15:58:48', '2026-10-16 07:29:58', '2026-09-16 13:29:58'),
(4, 1, 'f5976f9f493b80efc074bd153b83dcd96fccb84555dc081ca3d60e96e2701ff4', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '::1', '2026-09-21 19:54:03', '2026-10-19 11:51:26', '2026-09-19 17:51:26'),
(5, 9, '267775b6bfedc0246686b4bae146e61d11b409dc5a11614b18699c66ccad4d40', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '::1', '2026-09-21 20:15:22', '2026-10-21 14:15:22', '2026-09-21 20:15:22'),
(6, 1, 'a9a51e658a1a562f193f0c974bf9856963a39ed1524b8dd92e24c662670835a6', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', '::1', '2026-09-21 20:18:10', '2026-10-21 14:18:10', '2026-09-21 20:18:10'),
(7, 9, 'bef8b999cb24f0ee1d345a0feb9dd374de93d8ad50962256f74f1abbdd69d8a3', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', '::1', '2026-09-27 15:48:01', '2026-10-21 16:43:21', '2026-09-21 22:43:21'),
(8, 1, 'de130fa5bc13d4929da76abe0c878a0b00be27f618d37d8e3a98ce94f57e0473', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', '::1', '2026-09-24 08:22:19', '2026-10-23 08:29:35', '2026-09-23 14:29:35'),
(9, 11, '7e281fdc314b1cca225247864060032ac2228df5a3a5300061907a4c069adafd', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', '::1', '2026-09-24 13:04:33', '2026-10-24 07:04:33', '2026-09-24 13:04:33'),
(10, 1, '757b9ffacd60ce37da4c152e283c4feed4f52946c968f551408618ccab87a9f7', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Edg/153.0.0.0 Mobile Safari/537.36', '::1', '2026-09-27 09:16:37', '2026-10-24 09:47:02', '2026-09-24 15:47:02'),
(11, 9, '80db7ae74b859c53150ebfb3a72bab67392a3e87bb863e2d531ffe086c676de1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', '::1', '2026-09-28 08:25:25', '2026-10-27 14:06:36', '2026-09-27 20:06:36'),
(12, 1, '33e7237eb3fbddb4049274463f2e317ce65f689dcbb4a3111f5c494b082bce04', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', '::1', '2026-09-28 16:00:21', '2026-10-27 16:05:15', '2026-09-27 22:05:15'),
(13, 1, 'b3953bbf6a8e6a5065302fb0e44754bce68de33ed957ed4c799fc5b54a16444f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', '::1', '2026-09-28 08:26:26', '2026-10-28 02:26:26', '2026-09-28 08:26:26'),
(14, 9, '33ac57cfb1dbeca557304aa879299abc80065562867bf7e15fd59405b1f4485c', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', '::1', '2026-09-29 10:30:43', '2026-10-28 12:14:35', '2026-09-28 18:14:35'),
(15, 1, '50562d63eb90160f928409ef0f2988e8779ce531f766487aee832eaf60ba4c5f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-01 14:24:29', '2026-10-30 05:55:45', '2026-09-30 11:55:45'),
(16, 9, '39ec09805194116934aca798bc1f103df8b03c818f3afb7c1ab374c126ecfebb', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-02 12:56:26', '2026-11-01 06:56:26', '2026-10-02 12:56:26'),
(17, 1, '159b84833d7b2ca410845e800db3ae77d173e8498af7fed6927aa0fbbf5b1fe6', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-03 21:17:46', '2026-11-01 09:56:55', '2026-10-02 15:56:55'),
(18, 9, '20ec2c5cd1d81c87c991413f6dbe29371aacc6e7bf2ecadab0f5a57631329afc', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-03 21:37:35', '2026-11-02 15:37:35', '2026-10-03 21:37:35'),
(19, 1, '7f445dbea6a7b0c9015f12b3a70b334f463636d12f6a7c409c6d22717cadadcc', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-04 01:20:32', '2026-11-02 15:42:30', '2026-10-03 21:42:30'),
(20, 11, '098881bcb07deaf3617cd13c4b65b1d58423a7528771f01e9c64aa13423376bd', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-04 20:51:08', '2026-11-03 14:51:08', '2026-10-04 20:51:08'),
(21, 1, 'd9e1147f29e90084917997486e2482cf17c5c5c949a99727d4d253591e3553b8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '::1', '2026-10-06 14:18:14', '2026-11-03 15:01:31', '2026-10-04 21:01:31'),
(22, 1, 'efdda2ae58f2dee43dce483318b1369df2f6e630d87e9ba9dc1fbc7d725062b0', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '175.176.15.175', '2026-10-07 12:24:08', '2026-11-05 13:56:47', '2026-10-06 09:56:47'),
(23, 1, 'c861443521929260906336792df7581fe454f5d80d8f982549f90c0ae8f198be', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '182.255.40.189', '2026-10-06 11:05:33', '2026-11-05 15:05:33', '2026-10-06 11:05:33'),
(24, 11, 'fa8ae1a93ca5c83a14c658d5c4330228e02b13093b87099211f3d56f65ef9828', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '182.255.40.189', '2026-10-06 11:59:34', '2026-11-05 15:59:34', '2026-10-06 11:59:34'),
(25, 9, 'b2f6139472b90bf682d2a4e338795b28e30c2631fec25d9ed842f1c249cdc1c6', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36 Edg/154.0.0.0', '182.255.40.189', '2026-10-06 12:06:43', '2026-11-05 16:06:43', '2026-10-06 12:06:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `fullname` varchar(200) NOT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `name_suffix` varchar(20) DEFAULT NULL,
  `role` enum('admin','staff','guest') NOT NULL DEFAULT 'guest',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(10) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `profile_photo`, `fullname`, `first_name`, `middle_name`, `last_name`, `name_suffix`, `role`, `created_at`, `reset_token`, `reset_token_expires`) VALUES
(1, 'admin', '$2y$10$Rs7DfGTjLERZLzoWSfCVGeVFp0ezBWo.Dl4O8bPYQ7FMJETex2bS6', 'johnpaulnavarro0105@gmail.com', 'profile_1790236052.png', 'John Paul Navarro', NULL, NULL, NULL, NULL, 'admin', '2026-07-09 01:25:43', NULL, NULL),
(7, 'yoanna', '!reset-required!bcc29cf22a008223ee74f2f2e05fefa749d021631058bc826426f95900b915ab', 'teodoroyoannasophia@gmail.com', NULL, 'yoanna teodoro', NULL, NULL, NULL, NULL, 'staff', '2026-07-21 01:12:37', NULL, NULL),
(8, 'john', '$2y$10$c8h8Vx2INRRuO3cVqY7TleDnuFZ/HeaFors3zEqjuMH3o2qRYWdmm', 'trimurutempest55@gmail.com', NULL, 'John Paul Onia Navarro', NULL, NULL, NULL, NULL, 'guest', '2026-08-06 17:04:17', NULL, NULL),
(9, 'Rinn', '$2y$10$2lsoJ1PmJE8yvOeDNNjB0eguFLnC0ejobA/Aag7.BReJ4vPyk1Byy', 'kanonshibuya33@gmail.com', NULL, 'Rinn O Yoshida', NULL, NULL, NULL, NULL, 'guest', '2026-09-15 05:09:50', NULL, NULL),
(11, 'paul', '$2y$10$uTy0TH9awH5CdBY0Lmut6eQtv3PbJmgBzzqbI1NPQw3IfgPg5oBwm', 'johnpaulnavarro146@gmail.com', 'profile_1790230622.png', 'john paul navarro', NULL, NULL, NULL, NULL, 'staff', '2026-09-24 04:42:32', NULL, NULL),
(13, 'johnpaul', '$2y$10$lbK43joG5guG0nrQo0Qlu.gBVZ7xMtBrQCaq6HLaVJOuFPuZ1KSgu', 'fujiwarachika034@gmail.com', NULL, 'John paul Navarro', NULL, NULL, NULL, NULL, 'guest', '2026-09-28 00:35:16', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_terms_acceptance`
--

CREATE TABLE `user_terms_acceptance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `terms_version` varchar(20) NOT NULL,
  `accepted_at` datetime DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_terms_acceptance`
--

INSERT INTO `user_terms_acceptance` (`id`, `user_id`, `terms_version`, `accepted_at`, `ip_address`) VALUES
(1, 8, 'd41d8cd9', '2026-09-15 12:36:26', '::1'),
(2, 9, 'd41d8cd9', '2026-09-16 09:00:08', '::1'),
(3, 8, '430121db', '2026-09-16 12:32:17', '::1'),
(4, 9, '430121db', '2026-09-21 20:15:28', '::1');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_featured` (`is_featured`);

--
-- Indexes for table `activity_bookings`
--
ALTER TABLE `activity_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `idx_guest_id` (`guest_id`),
  ADD KEY `idx_activity_id` (`activity_id`),
  ADD KEY `idx_reference_number` (`reference_number`),
  ADD KEY `idx_booking_date` (`booking_date`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_booking_status` (`booking_status`);

--
-- Indexes for table `activity_variations`
--
ALTER TABLE `activity_variations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `bak_2026_10_blocked_dates`
--
ALTER TABLE `bak_2026_10_blocked_dates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_block` (`item_type`,`item_id`,`block_date`),
  ADD KEY `idx_item` (`item_type`,`item_id`,`block_date`);

--
-- Indexes for table `bak_2026_10_food_bookings`
--
ALTER TABLE `bak_2026_10_food_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `food_id` (`food_id`),
  ADD KEY `idx_fb_pkg` (`package_id`),
  ADD KEY `idx_package` (`package_id`);

--
-- Indexes for table `bak_2026_10_food_items`
--
ALTER TABLE `bak_2026_10_food_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_available` (`is_available`);

--
-- Indexes for table `bak_2026_10_house_bookings`
--
ALTER TABLE `bak_2026_10_house_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `house_id` (`house_id`),
  ADD KEY `idx_hb_pkg` (`package_id`),
  ADD KEY `idx_package` (`package_id`);

--
-- Indexes for table `bak_2026_10_package_bookings`
--
ALTER TABLE `bak_2026_10_package_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `fk_pkg_house` (`house_booking_id`),
  ADD KEY `fk_pkg_tour` (`tour_booking_id`),
  ADD KEY `fk_pkg_food` (`food_booking_id`),
  ADD KEY `idx_pkg_ref` (`reference_number`),
  ADD KEY `idx_pkg_guest` (`guest_id`);

--
-- Indexes for table `bak_2026_10_site_content`
--
ALTER TABLE `bak_2026_10_site_content`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bak_2026_10_tour_bookings`
--
ALTER TABLE `bak_2026_10_tour_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `tour_id` (`tour_id`),
  ADD KEY `idx_tb_pkg` (`package_id`),
  ADD KEY `idx_package` (`package_id`);

--
-- Indexes for table `blocked_dates`
--
ALTER TABLE `blocked_dates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_block` (`item_type`,`item_id`,`block_date`),
  ADD KEY `idx_item` (`item_type`,`item_id`,`block_date`);

--
-- Indexes for table `booking_payments`
--
ALTER TABLE `booking_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_booking_payment` (`booking_type`,`booking_id`,`payment_type`),
  ADD KEY `idx_bp_received` (`received_at`),
  ADD KEY `idx_bp_booking` (`booking_type`,`booking_id`);

--
-- Indexes for table `food_bookings`
--
ALTER TABLE `food_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `food_id` (`food_id`),
  ADD KEY `idx_fb_pkg` (`package_id`),
  ADD KEY `idx_package` (`package_id`),
  ADD KEY `idx_fb_res_paid` (`reservation_paid_at`),
  ADD KEY `idx_fb_created` (`created_at`);

--
-- Indexes for table `food_items`
--
ALTER TABLE `food_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_available` (`is_available`);

--
-- Indexes for table `food_orders`
--
ALTER TABLE `food_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking` (`booking_id`,`booking_type`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `food_packages`
--
ALTER TABLE `food_packages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `guests`
--
ALTER TABLE `guests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `houses`
--
ALTER TABLE `houses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `house_bookings`
--
ALTER TABLE `house_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `house_id` (`house_id`),
  ADD KEY `idx_hb_pkg` (`package_id`),
  ADD KEY `idx_package` (`package_id`),
  ADD KEY `idx_hb_res_paid` (`reservation_paid_at`),
  ADD KEY `idx_hb_created` (`created_at`);

--
-- Indexes for table `house_gallery`
--
ALTER TABLE `house_gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `house_id` (`house_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_identifier_ip` (`identifier`,`ip_address`),
  ADD KEY `idx_locked_until` (`locked_until`);

--
-- Indexes for table `migration_2026_10_audit`
--
ALTER TABLE `migration_2026_10_audit`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migration_2026_10_owner_review`
--
ALTER TABLE `migration_2026_10_owner_review`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `overall_feedback`
--
ALTER TABLE `overall_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `package_bookings`
--
ALTER TABLE `package_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `fk_pkg_house` (`house_booking_id`),
  ADD KEY `fk_pkg_tour` (`tour_booking_id`),
  ADD KEY `fk_pkg_food` (`food_booking_id`),
  ADD KEY `idx_pkg_ref` (`reference_number`),
  ADD KEY `idx_pkg_guest` (`guest_id`),
  ADD KEY `idx_pb_res_paid` (`reservation_paid_at`),
  ADD KEY `idx_pb_created` (`created_at`);

--
-- Indexes for table `package_items`
--
ALTER TABLE `package_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `package_id` (`package_id`),
  ADD KEY `food_id` (`food_id`);

--
-- Indexes for table `site_content`
--
ALTER TABLE `site_content`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_site_content_key` (`section_name`,`content_key`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `tours`
--
ALTER TABLE `tours`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tour_activities`
--
ALTER TABLE `tour_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tour_id` (`tour_id`);

--
-- Indexes for table `tour_bookings`
--
ALTER TABLE `tour_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `tour_id` (`tour_id`),
  ADD KEY `idx_tb_pkg` (`package_id`),
  ADD KEY `idx_package` (`package_id`),
  ADD KEY `idx_tb_res_paid` (`reservation_paid_at`),
  ADD KEY `idx_tb_created` (`created_at`);

--
-- Indexes for table `tour_gallery`
--
ALTER TABLE `tour_gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tour_id` (`tour_id`);

--
-- Indexes for table `tour_islands`
--
ALTER TABLE `tour_islands`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tour_id` (`tour_id`);

--
-- Indexes for table `trusted_devices`
--
ALTER TABLE `trusted_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_token` (`device_token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_token` (`device_token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_terms_acceptance`
--
ALTER TABLE `user_terms_acceptance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_version` (`user_id`,`terms_version`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `activity_bookings`
--
ALTER TABLE `activity_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_variations`
--
ALTER TABLE `activity_variations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bak_2026_10_blocked_dates`
--
ALTER TABLE `bak_2026_10_blocked_dates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `bak_2026_10_food_bookings`
--
ALTER TABLE `bak_2026_10_food_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `bak_2026_10_food_items`
--
ALTER TABLE `bak_2026_10_food_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `bak_2026_10_house_bookings`
--
ALTER TABLE `bak_2026_10_house_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `bak_2026_10_package_bookings`
--
ALTER TABLE `bak_2026_10_package_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `bak_2026_10_site_content`
--
ALTER TABLE `bak_2026_10_site_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `bak_2026_10_tour_bookings`
--
ALTER TABLE `bak_2026_10_tour_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `blocked_dates`
--
ALTER TABLE `blocked_dates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `booking_payments`
--
ALTER TABLE `booking_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `food_bookings`
--
ALTER TABLE `food_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `food_items`
--
ALTER TABLE `food_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `food_orders`
--
ALTER TABLE `food_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `food_packages`
--
ALTER TABLE `food_packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `guests`
--
ALTER TABLE `guests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `houses`
--
ALTER TABLE `houses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `house_bookings`
--
ALTER TABLE `house_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `house_gallery`
--
ALTER TABLE `house_gallery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `migration_2026_10_audit`
--
ALTER TABLE `migration_2026_10_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

--
-- AUTO_INCREMENT for table `migration_2026_10_owner_review`
--
ALTER TABLE `migration_2026_10_owner_review`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `overall_feedback`
--
ALTER TABLE `overall_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `package_bookings`
--
ALTER TABLE `package_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `package_items`
--
ALTER TABLE `package_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_content`
--
ALTER TABLE `site_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=298;

--
-- AUTO_INCREMENT for table `tours`
--
ALTER TABLE `tours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tour_activities`
--
ALTER TABLE `tour_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tour_bookings`
--
ALTER TABLE `tour_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `tour_gallery`
--
ALTER TABLE `tour_gallery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tour_islands`
--
ALTER TABLE `tour_islands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `trusted_devices`
--
ALTER TABLE `trusted_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `user_terms_acceptance`
--
ALTER TABLE `user_terms_acceptance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_bookings`
--
ALTER TABLE `activity_bookings`
  ADD CONSTRAINT `activity_bookings_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_bookings_ibfk_2` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `activity_variations`
--
ALTER TABLE `activity_variations`
  ADD CONSTRAINT `activity_variations_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `food_bookings`
--
ALTER TABLE `food_bookings`
  ADD CONSTRAINT `food_bookings_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `food_bookings_ibfk_2` FOREIGN KEY (`food_id`) REFERENCES `food_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `guests`
--
ALTER TABLE `guests`
  ADD CONSTRAINT `guests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `house_bookings`
--
ALTER TABLE `house_bookings`
  ADD CONSTRAINT `house_bookings_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `house_bookings_ibfk_2` FOREIGN KEY (`house_id`) REFERENCES `houses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `house_gallery`
--
ALTER TABLE `house_gallery`
  ADD CONSTRAINT `house_gallery_ibfk_1` FOREIGN KEY (`house_id`) REFERENCES `houses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `overall_feedback`
--
ALTER TABLE `overall_feedback`
  ADD CONSTRAINT `overall_feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `package_bookings`
--
ALTER TABLE `package_bookings`
  ADD CONSTRAINT `fk_pkg_food` FOREIGN KEY (`food_booking_id`) REFERENCES `food_bookings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pkg_guest` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pkg_house` FOREIGN KEY (`house_booking_id`) REFERENCES `house_bookings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pkg_tour` FOREIGN KEY (`tour_booking_id`) REFERENCES `tour_bookings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `package_items`
--
ALTER TABLE `package_items`
  ADD CONSTRAINT `package_items_ibfk_1` FOREIGN KEY (`package_id`) REFERENCES `food_packages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `package_items_ibfk_2` FOREIGN KEY (`food_id`) REFERENCES `food_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tour_activities`
--
ALTER TABLE `tour_activities`
  ADD CONSTRAINT `tour_activities_ibfk_1` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tour_bookings`
--
ALTER TABLE `tour_bookings`
  ADD CONSTRAINT `tour_bookings_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tour_bookings_ibfk_2` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tour_gallery`
--
ALTER TABLE `tour_gallery`
  ADD CONSTRAINT `tour_gallery_ibfk_1` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tour_islands`
--
ALTER TABLE `tour_islands`
  ADD CONSTRAINT `tour_islands_ibfk_1` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `trusted_devices`
--
ALTER TABLE `trusted_devices`
  ADD CONSTRAINT `trusted_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
