import { Howl } from 'howler';

class SoundManager {
    constructor() {
        this.enabled = true;
        this.volume = 0.5;
        this.sounds = {};
        this.loaded = false;
    }

    init() {
        if (this.loaded) return;

        // Use simple tone generation since we don't have sound files yet
        // In production, replace with actual sound file URLs
        const soundDefs = {
            deal: { src: ['/sounds/deal.mp3'], volume: 0.4 },
            play: { src: ['/sounds/play.mp3'], volume: 0.5 },
            flip: { src: ['/sounds/flip.mp3'], volume: 0.4 },
            win: { src: ['/sounds/win.mp3'], volume: 0.7 },
            lose: { src: ['/sounds/lose.mp3'], volume: 0.5 },
            tick: { src: ['/sounds/tick.mp3'], volume: 0.3 },
            notification: { src: ['/sounds/notification.mp3'], volume: 0.5 },
        };

        for (const [name, config] of Object.entries(soundDefs)) {
            this.sounds[name] = new Howl({
                ...config,
                volume: config.volume * this.volume,
                preload: true,
                onloaderror: () => {
                    // Silently fail if sound files don't exist
                },
            });
        }

        this.loaded = true;
    }

    play(name) {
        if (!this.enabled) return;
        if (!this.loaded) this.init();
        this.sounds[name]?.play();
    }

    setVolume(vol) {
        this.volume = vol;
        for (const sound of Object.values(this.sounds)) {
            sound.volume(vol);
        }
    }

    toggle() {
        this.enabled = !this.enabled;
        return this.enabled;
    }

    mute() {
        this.enabled = false;
    }

    unmute() {
        this.enabled = true;
    }
}

export default new SoundManager();
