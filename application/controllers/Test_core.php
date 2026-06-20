<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Test_core extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('User_model');
        $this->load->model('Ledger_model');
        $this->load->database();
    }

    public function index() {
        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>Synapse Core Models Integration Test</h1>";
        echo "<pre>";

        try {
            // --- Cleanup any existing test data ---
            $this->db->delete('transactions', ['user_id' => 1]);
            $this->db->delete('rentals', ['user_id' => 1]);
            $this->db->delete('users', ['phone' => '081234567890']);
            $this->db->delete('users', ['phone' => '081234567891']);

            // ==========================================
            // TEST A: User Creation
            // ==========================================
            echo "=== TEST A: Create User 1 ===\n";
            $user1_data = [
                'phone'    => '081234567890',
                'password' => password_hash('password123', PASSWORD_BCRYPT),
            ];
            $user1_id = $this->User_model->create_user($user1_data);

            if ($user1_id === false) {
                throw new Exception('Failed to create User 1');
            }

            $user1 = $this->User_model->get_user_by_phone('081234567890');
            echo "User 1 created:\n";
            print_r($user1);

            // Verify invite_code exists and is 6 chars
            if (empty($user1->invite_code) || strlen($user1->invite_code) !== 6) {
                throw new Exception('Invite code missing or invalid: ' . ($user1->invite_code ?? 'null'));
            }
            echo "✓ Invite code generated: {$user1->invite_code}\n\n";

            // ==========================================
            // TEST B: Hierarchy (User 2 with parent = User 1)
            // ==========================================
            echo "=== TEST B: Create User 2 (child of User 1) ===\n";
            $user2_data = [
                'phone'     => '081234567891',
                'password'  => password_hash('password123', PASSWORD_BCRYPT),
                'parent_id' => $user1_id,
            ];
            $user2_id = $this->User_model->create_user($user2_data);

            if ($user2_id === false) {
                throw new Exception('Failed to create User 2');
            }

            $user2 = $this->User_model->get_user_by_phone('081234567891');
            echo "User 2 created:\n";
            print_r($user2);

            if ($user2->parent_id != $user1_id) {
                throw new Exception('Parent ID not set correctly');
            }
            echo "✓ Parent ID correctly set to {$user1_id}\n\n";

            // Verify downlines
            $downlines_l1 = $this->User_model->get_downlines($user1_id, 1);
            echo "Level 1 Downlines (B):\n";
            print_r($downlines_l1);

            $downlines_l2 = $this->User_model->get_downlines($user1_id, 2);
            echo "Level 2 Downlines (C):\n";
            print_r($downlines_l2);
            echo "\n";

            // ==========================================
            // TEST C: Ledger & Balance (Deposit + Rental Payment)
            // ==========================================
            echo "=== TEST C: Ledger Transactions ===\n";

            // Deposit: +500,000
            echo "Step 1: Deposit +500,000\n";
            $ok = $this->Ledger_model->insert_transaction(
                $user1_id,
                'deposit',
                500000,
                'Test deposit',
                'test',
                1
            );
            if (!$ok) throw new Exception('Deposit transaction failed');
            echo "✓ Deposit recorded\n";

            // Rental Payment: -76,000
            echo "Step 2: Rental Payment -76,000\n";
            $ok = $this->Ledger_model->insert_transaction(
                $user1_id,
                'rental_payment',
                -76000,
                'Test rental payment',
                'rentals',
                1
            );
            if (!$ok) throw new Exception('Rental payment transaction failed');
            echo "✓ Rental payment recorded\n\n";

            // ==========================================
            // TEST D: Verification
            // ==========================================
            echo "=== TEST D: Verify Final Balance ===\n";
            $user1_final = $this->User_model->get_user_by_phone('081234567890');
            echo "User 1 final state:\n";
            print_r($user1_final);

            $expected_balance = 424000.00;
            $actual_balance = (float) $user1_final->balance;

            echo "\nExpected: {$expected_balance}\n";
            echo "Actual:   {$actual_balance}\n";

            if ($actual_balance === $expected_balance) {
                echo "\n✅ TEST PASSED: Balance matches expected 424,000\n";
            } else {
                echo "\n❌ TEST FAILED: Balance mismatch!\n";
            }

            // Show transaction log
            echo "\n--- Transaction Log ---\n";
            $txns = $this->db->get_where('transactions', ['user_id' => $user1_id])->result();
            print_r($txns);

        } catch (Exception $e) {
            echo "\n❌ ERROR: " . $e->getMessage() . "\n";
            echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
        }

        echo "</pre>";
    }
}
