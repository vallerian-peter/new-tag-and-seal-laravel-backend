<?php

namespace App\Mail;

use App\Models\FarmUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FarmUserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public FarmUser $farmUser;
    public string $email;
    public string $password;

    /**
     * Create a new message instance.
     */
    public function __construct(FarmUser $farmUser, string $email, string $password)
    {
        $this->farmUser = $farmUser;
        $this->email = $email;
        $this->password = $password;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $subject = 'You have been invited to Livestock - Tag and Seal';

        $html = view('emails.farm_user_invitation', [
            'farmUser' => $this->farmUser,
            'email'    => $this->email,
            'password' => $this->password,
        ])->render();

        return $this->subject($subject)
            ->html($html);
    }
}


