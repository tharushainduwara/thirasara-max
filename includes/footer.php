    </main>

    <!-- FOOTER-->
    <footer
        class="bg-brand-dark text-gray-400 border-t border-brand-border mt-12">

        <div
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

            <div
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">


                <!-- Brand -->

                <div>

                    <div class="flex items-center gap-3 mb-4">

                        <div
                            class="w-11 h-11 rounded-full flex items-center justify-center overflow-hidden">

                            <img
                                src="<?php echo APP_URL; ?>/assets/images/Logo.png"
                                alt="Thirasara Max Mobile"
                                class="h-14 w-auto rounded-full object-contain"
                                onerror="this.onerror=null; this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';">


                        </div>

                        <div>

                            <div
                                class="text-sm font-extrabold text-white tracking-tight">
                                THIRASARA MAX
                            </div>

                            <div
                                class="text-[9px] uppercase tracking-[0.2em] text-gray-500 font-semibold">
                                Mobile Store
                            </div>

                        </div>

                    </div>


                    <p
                        class="text-sm leading-relaxed text-gray-500">
                        Your trusted destination for mobile accessories,
                        repairs, and reliable after-sales service.
                    </p>

                </div>


                <!-- Quick Links -->

                <div>

                    <h3
                        class="text-sm font-bold text-white mb-4">
                        Quick Links
                    </h3>

                    <div class="space-y-2.5">

                        <a
                            href="<?php echo APP_URL; ?>/index.php"
                            class="block text-sm hover:text-white transition">
                            Home
                        </a>

                        <a
                            href="<?php echo APP_URL; ?>/products.php"
                            class="block text-sm hover:text-white transition">
                            Products
                        </a>

                        <a
                            href="<?php echo APP_URL; ?>/contact.php"
                            class="block text-sm hover:text-white transition">
                            Contact Us
                        </a>

                        <a
                            href="<?php echo APP_URL; ?>/about.php"
                            class="block text-sm hover:text-white transition">
                            About Us
                        </a>

                    </div>

                </div>


                <!-- Services -->

                <div>

                    <h3
                        class="text-sm font-bold text-white mb-4">
                        Services
                    </h3>

                    <div class="space-y-2.5">

                        <a
                            href="<?php echo APP_URL; ?>/repair-booking.php"
                            class="block text-sm hover:text-white transition">
                            Device Repair
                        </a>

                        <a
                            href="<?php echo APP_URL; ?>/warranty-check.php"
                            class="block text-sm hover:text-white transition">
                            Warranty Check
                        </a>

                        <a
                            href="<?php echo APP_URL; ?>/inquiry.php"
                            class="block text-sm hover:text-white transition">
                            Customer Inquiries
                        </a>

                    </div>

                </div>


                <!-- Contact -->

                <div>

                    <h3
                        class="text-sm font-bold text-white mb-4">
                        Contact
                    </h3>

                    <div class="space-y-3">

                        <div class="flex gap-3">

                            <i
                                data-lucide="phone"
                                class="w-4 h-4 mt-0.5 text-brand-red flex-shrink-0"></i>

                            <span class="text-sm">
                                Contact us for assistance
                            </span>

                        </div>


                        <div class="flex gap-3">

                            <i
                                data-lucide="map-pin"
                                class="w-4 h-4 mt-0.5 text-brand-red flex-shrink-0"></i>

                            <span class="text-sm">
                                Thirasara Max Mobile
                            </span>

                        </div>


                        <div class="flex gap-3">

                            <i
                                data-lucide="mail"
                                class="w-4 h-4 mt-0.5 text-brand-red flex-shrink-0"></i>

                            <a
                                href="<?php echo APP_URL; ?>/inquiry.php"
                                class="text-sm hover:text-white transition">
                                Send an inquiry
                            </a>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Bottom -->

            <div
                class="border-t border-brand-border mt-8 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3">

                <p class="text-xs text-gray-600">

                    &copy;
                    <?php echo date('Y'); ?>
                    Thirasara Max Mobile. All rights reserved.

                </p>


                <div class="flex items-center gap-4 text-xs">

                    <a
                        href="<?php echo APP_URL; ?>/privacy.php"
                        class="text-gray-600 hover:text-gray-300 transition">
                        Privacy
                    </a>

                    <a
                        href="<?php echo APP_URL; ?>/terms.php"
                        class="text-gray-600 hover:text-gray-300 transition">
                        Terms
                    </a>

                </div>

            </div>

        </div>

    </footer>


    <!-- Initialize Lucide Icons -->
    <script>
        lucide.createIcons();
    </script>

    </body>

    </html>