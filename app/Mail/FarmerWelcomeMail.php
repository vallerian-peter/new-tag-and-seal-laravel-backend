<?php

namespace App\Mail;

use App\Models\Farmer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FarmerWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public Farmer $farmer;
    public string $email;
    public string $password;

    /**
     * Create a new message instance.
     */
    public function __construct(Farmer $farmer, string $email, string $password)
    {
        $this->farmer = $farmer;
        $this->email = $email;
        $this->password = $password;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $subject = 'Welcome to Livestock - Tag and Seal';

        $html = view('emails.farmer_welcome', [
            'farmer' => $this->farmer,
            'email'    => $this->email,
            'password' => $this->password,
        ])->render();

        return $this->subject($subject)
            ->html($html);
    }
}
